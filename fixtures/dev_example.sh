#!/bin/bash

MODULE=islandora_spreadsheet_ingest
MG=isi
MIGRATIONS=(${drush migrate:status --group=$MG --field=id})
APACHE_USER=www-data

function reset_all() {
  for MIG in "${MIGRATIONS[@]}"; do
    drush migrate:reset-status $MIG || return 2
  done
}

DEST_LINK="${drush drupal:directory files}/isifixturefiles"
FIXTURES="${drush drupal:directory $MODULE}/fixtures"

sudo -u $APACHE_USER -- ln -s -f $FIXTURES $DEST_LINK && \
# Cheap script to facilitate dev.
reset_all && \
# Known issue with content sync causes OOM. Disable the context config.
drush migrate:rollback -v --group=$MG && \
drush pm-uninstall $MODULE && \
drush en $MODULE && \
drush config:set migrate_plus.migration_group.isi shared_configuration.source.file "$FIXTURES/migration_example.csv" && \
drush cr && \
drush-test-no-empty $MODULE && \
reset_all && \
# Update user as needed.
sudo -u $APACHE_USER -- drush migrate:batch-import -u 1 -v --uri=http://localhost --execute-dependencies --group=$MG ;\
for MIG in "${MIGRATIONS[@]}"; do
  echo $MIG
  drush migrate:messages $MIG
done
