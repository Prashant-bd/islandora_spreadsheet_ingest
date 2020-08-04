<?php

/**
 * @file
 * Module API documentation.
 */

/**
 * This hook allows modules to provide templates.
 *
 * @return array
 *   An associative array mapping an id to an array with the keys 'name', 'uri'
 *   and 'dsids' containing a human readable name, URI, valid output dsids for
 *   the template, and an optional 'self_transform' to specify which self
 *   transform should be applied for this template (these are populated from
 *   hook_xml_form_builder_get_self_transforms).
 */
function hook_islandora_spreadsheet_ingest_templates() {
  return array(
    array(
      'id' => 'my_template_id',
      'name' => 'my awesome template',
      'uri' => 'uri/to_my/template',
      'dsids' => array('MODS'),
      'self_transform' => 'my_self_transform.xslt',
    ),
  );
}

/**
 * Allows modules to modify generated objects.
 *
 * Before the alter hook is called, parent relationships are added to the
 * object. Immediately before this, autoCommit is turned off for the object's
 * relationships; committance is not performed until all alterations are
 * completed. Be aware of this when using the alteration hook to add/modify
 * relationships.
 *
 * @param AbstractObject $object
 *   The object that was just ingested.
 */
function hook_islandora_spreadsheet_ingest_object_alter(AbstractObject $object) {
  if (in_array('islandora:cool_model', $object->models)) {
    $object->state = 'I';
  }
}