<?php

namespace Drupal\islandora_spreadsheet_ingest\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

use Drupal\islandora_spreadsheet_ingest\RequestInterface;

/**
 * Defines the Request entity.
 *
 * @ConfigEntityType(
 *   id = "isi_request",
 *   label = @Translation("Islandora Spreadsheet Ingest Request"),
 *   handlers = {
 *     "list_builder" = "Drupal\islandora_spreadsheet_ingest\Controller\RequestListBuilder",
 *     "form" = {
 *       "activate" = "Drupal\islandora_spreadsheet_ingest\Form\Ingest\Review",
 *       "add" = "Drupal\islandora_spreadsheet_ingest\Form\Ingest\FileUpload",
 *       "delete" = "Drupal\islandora_spreadsheet_ingest\Form\RequestDeleteForm",
 *       "edit" = "Drupal\islandora_spreadsheet_ingest\Form\Ingest\FileUpload",
 *       "map" = "Drupal\islandora_spreadsheet_ingest\Form\Ingest\Mapping",
 *       "view" = "Drupal\islandora_spreadsheet_ingest\Form\Ingest\Review",
 *     }
 *   },
 *   config_prefix = "request",
 *   admin_permission = "administer site configuration",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "sheet",
 *     "originalMapping",
 *     "mappings",
 *     "active",
 *     "owner",
 *   },
 *   links = {
 *     "canonical" = "/admin/content/islandora_spreadsheet_ingest/{isi_request}",
 *     "activate-form" = "/admin/content/islandora_spreadsheet_ingest/{isi_request}/activate",
 *     "edit-form" = "/admin/content/islandora_spreadsheet_ingest/{isi_request}/edit",
 *     "map-form" = "/admin/content/islandora_spreadsheet_ingest/{isi_request}/mapping",
 *     "delete-form" = "/admin/content/islandora_spreadsheet_ingest/{isi_request}/delete",
 *   }
 * )
 */
class Request extends ConfigEntityBase implements RequestInterface {

  /**
   * The ID of the request.
   *
   * @var string
   */
  protected $id;

  /**
   * The request's label.
   *
   * @var string
   */
  protected $label;

  /**
   * Coordinates for where to find the particular worksheet.
   *
   * Includes:
   * - sheet: The name of the worksheet.
   * - file: An array of file IDs... though there should just be one.
   *
   * @var array
   */
  protected $sheet;

  /**
   * A representation of the original mapping.
   *
   * Should only ever be a "migration_group:*" kind of thing...
   *
   * @var string
   */
  protected $originalMapping = 'migration_group:isi';

  /**
   * The associative array of mappings.
   *
   * Mapping migration names to:
   * - original_migration_id: The name of the original migration
   * - mappings: An associative array mapping field/property names to an
   *   associative array containing:
   *   - pipeline: The array of process plugin definitions.
   *
   * May be NULL if not yet set.
   *
   * @var array|null
   */
  protected $mappings = NULL;

  /**
   * Whether this request should be eligible to be processed.
   *
   * @var bool
   *
   * @todo Review whether or not the core ConfigEntityBase's "status" may cover
   *   the same situation.
   */
  protected $active = FALSE;

  /**
   * The creator/owner of this request.
   *
   * @var string
   */
  protected $owner = NULL;

  /**
   * {@inheritdoc}
   */
  public function getOriginalMapping() {
    return $this->originalMapping;
  }

  /**
   * {@inheritdoc}
   */
  public function getActive() {
    return $this->active;
  }

  /**
   * {@inheritdoc}
   */
  public function getSheet() {
    return $this->sheet;
  }

  /**
   * {@inheritdoc}
   */
  public function getMappings() {
    return $this->mappings;
  }

  /**
   * {@inheritdoc}
   */
  public function getOwner() {
    return $this->owner;
  }

}