<?php

namespace Drupal\islandora_spreadsheet_ingest;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Psr\Log\LoggerInterface;

use Drupal\migrate_plus\Entity\MigrationInterface;

/**
 * Derive migrations for a given request.
 */
class MigrationDeriver implements MigrationDeriverInterface {

  /**
   * Logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Migration group deriver service.
   *
   * @var \Drupal\islandora_spreadsheet_ingest\MigrationGroupDeriverInterface
   */
  protected $migrationGroupDeriver;

  /**
   * Request config entity storage.
   *
   * @var \Drupal\Core\Config\Entity\ConfigEntityStorageInterface
   */
  protected $requestStorage;

  /**
   * Migration config entity storage.
   *
   * @var \Drupal\Core\Config\Entity\ConfigEntityStorageInterface
   */
  protected $migrationStorage;

  /**
   * Cache invalidator service.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected $cacheInvalidator;

  /**
   * Constructor.
   */
  public function __construct(
    LoggerInterface $logger,
    EntityTypeManagerInterface $entity_type_manager,
    CacheTagsInvalidatorInterface $invalidator,
    MigrationGroupDeriverInterface $migration_group_deriver
  ) {
    $this->logger = $logger;
    $this->entityTypeManager = $entity_type_manager;
    $this->migrationGroupDeriver = $migration_group_deriver;
    $this->requestStorage = $this->entityTypeManager->getStorage('isi_request');
    $this->migrationStorage = $this->entityTypeManager->getStorage('migration');
    $this->cacheInvalidator = $invalidator;
  }

  /**
   * {@inheritdoc}
   */
  public function getUsedColumns(array $mappings) {
    $mapping = [
      'get' => function ($step) {
        yield from (array) ($step['source'] ?? []);
      },
      'migration_lookup' => function ($step) {
        if (isset($step['source'])) {
          yield from (array) ($step['source'] ?? []);
        }
        if (isset($step['source_ids'])) {
          foreach ($step['source_ids'] as $ids) {
            yield from $ids;
          }
        }
      },
    ];

    foreach ($mappings as $info) {
      foreach ($info['pipeline'] as $process_step) {
        $plugin = $process_step['plugin'] ?? 'get';
        $mapper = $mapping[$plugin] ?? $mapping['get'];

        foreach ($mapper($process_step) as $source) {
          if (strpos($source, '@') !== 0) {
            yield $source;
          }
        }
      }
    }
  }

  /**
   * Remap referenced migration dependencies to their new names.
   *
   * @param \Drupal\migrate_plus\Entity\MigrationInterface $migration
   *   The original migration from which to scrape the dependencies.
   * @param string $new_mg
   *   The name of the new migration group, such that we can derive the new
   *   migration names.
   *
   * @return array
   *   An associative array as per migrations' "migration_dependencies"
   *   property.
   */
  protected function mapDependencies(MigrationInterface $migration, $new_mg) {
    $original_deps = $migration->get('migration_dependencies') ?? [];
    $deps = [];

    foreach ($original_deps as $type => $mig_deps) {
      $_deps = [];

      foreach ($mig_deps as $mig_dep) {
        if ($this->sameMigrationGroup($migration, $mig_dep)) {
          $_deps[] = $this->deriveMigrationName($new_mg, $mig_dep);
        }
      }

      $deps[$type] = $_deps;
    }

    return $deps;
  }

  /**
   * Derive the name of a migration.
   *
   * @param string $mg_name
   *   The name of the migration group to which the migration will belong.
   * @param string $target
   *   The name of the original migration.
   *
   * @return string
   *   The derived name.
   */
  protected function deriveMigrationName($mg_name, $target) {
    return "{$mg_name}_{$target}";
  }

  /**
   * Determine if a migration appears to be a part of the same migration group.
   *
   * @param \Drupal\migrate_plus\Entity\MigrationInterface $mig
   *   The original migration for comparison.
   * @param string $target
   *   The name/id of the migration to test.
   *
   * @return bool
   *   TRUE if it is the same; otherwise, FALSE.
   */
  protected function sameMigrationGroup(MigrationInterface $mig, $target) {
    $loaded_target = $this->migrationStorage->load($target);
    $mg = $loaded_target->get('migration_group');
    return $mg && $mg == $mig->get('migration_group');
  }

  /**
   * Generate steps with referenced migration names mapped.
   *
   * @param array $steps
   *   The array of process plugin definitions to map.
   * @param \Drupal\migrate_plus\Entity\MigrationInterface $mig
   *   The original migration for comparison.
   * @param string $mg_name
   *   The name of the migration group we are populating.
   */
  protected function mapStepMigrations(array $steps, MigrationInterface $mig, $mg_name) {
    foreach ($steps as $step) {
      $plugin = $step['plugin'] ?? 'get';
      if ($plugin == 'migration_lookup') {
        // Do the mapping.
        if (is_array($step['migration'])) {
          // Map the listed migrations, and any similar references under
          // "source_ids".
          foreach ($step['migration'] as &$mig_step) {
            if ($this->sameMigrationGroup($mig, $mig_step)) {
              $old_name = $mig_step;
              $mig_step = $this->deriveMigrationName($mg_name, $mig_step);
              $step['source_ids'][$mig_step] = $step['source_ids'][$old_name];
              unset($step['source_ids'][$old_name]);
            }
          }
          unset($mig_step);
        }
        elseif (is_string($step['migration'])) {
          // Just map the single migration.
          if ($this->sameMigrationGroup($mig, $step['migration'])) {
            $step['migration'] = $this->deriveMigrationName($mg_name, $step['migration']);
          }
        }
      }
      yield $step;
    }
  }

  /**
   * Generate the mapping of field names to name-adjusted process pipelines.
   *
   * @param array $processes
   *   Associative array mapping field names to process info.
   * @param \Drupal\migrate_plus\Entity\MigrationInterface $mig
   *   The migration from which we are deriving another migration.
   * @param string $mg_name
   *   The migration group we are populating.
   */
  protected function mapPipelineMigrations(array $processes, MigrationInterface $mig, $mg_name) {
    foreach ($processes as $name => $info) {
      yield $name => iterator_to_array($this->mapStepMigrations($info['pipeline'], $mig, $mg_name));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createAll(RequestInterface $request) {
    if (!$request->status() || !$request->getActive()) {
      $this->logger->info('Call to create on non-active request {id}.', ['id' => $request->id()]);
      return;
    }

    $mg_name = $this->migrationGroupDeriver->deriveName($request);

    assert($this->entityTypeManager->getStorage('migration_group')->load($mg_name));

    foreach ($request->getMappings() as $name => $info) {
      $original_migration = $this->migrationStorage->load($info['original_migration_id']);
      $derived_name = $this->deriveMigrationName($mg_name, $name);
      $info = [
        'id' => $derived_name,
        'label' => $original_migration->label(),
        'migration_group' => $mg_name,
        'source' => [
        /*
         *  XXX: Doesn't appear necessary to specify the columns?
         *   'columns' => array_unique(iterator_to_array($this->getUsedColumns($info['mappings']))),
         */
        ],
        'process' => iterator_to_array(
          $this->mapPipelineMigrations(
            $info['mappings'],
            $original_migration,
            $mg_name
          )
        ),
        'destination' => $original_migration->get('destination'),
        'dependencies' => array_merge_recursive(
          $original_migration->get('dependencies'),
          [
            'enforced' => [
              $request->getConfigDependencyKey() => [
                $request->getConfigDependencyName(),
              ],
            ],
          ]
        ),
        'migration_dependencies' => $this->mapDependencies($original_migration, $mg_name),
      ];

      $migration = $this->migrationStorage->load($derived_name) ?? $this->migrationStorage->create();
      foreach ($info as $key => $value) {
        $migration->set($key, $value);
      }
      $migration->save();
    }

    $this->invalidateTags();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll(RequestInterface $request) {
    // Nuke the storage for the given mgiration group.
    $this->migrationStorage->delete(
      $this->migrationStorage->loadByProperties([
        'migration_group' => $this->migrationGroupDeriver->deriveName($request),
      ])
    );

    $this->invalidateTags();
  }

  /**
   * Helper; invalidate the build migration plugin cache.
   */
  protected function invalidateTags() {
    $this->logger->debug('Invalidating cache for "migration_plugins"');
    $this->cacheInvalidator->invalidateTags(['migration_plugins']);
    $this->logger->info('Invalidated cache for "migration_plugins"');
  }

}
