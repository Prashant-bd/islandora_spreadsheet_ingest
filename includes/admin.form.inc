<?php

/**
 * @file
 * Module admin form.
 */

/**
 * Administration form.
 */
function islandora_spreadsheet_ingest_admin_form(array $form, array &$form_state) {
  module_load_include('inc', 'islandora', 'includes/utilities');
  form_load_include($form_state, 'inc', 'islandora_spreadsheet_ingest', 'includes/utilities');
  $templates = islandora_spreadsheet_ingest_get_templates();
  $options = array();
  foreach ($templates as $template) {
    $options[$template['id']] = array(
      $template['name'],
      implode(', ', $template['dsids']),
    );
    if (isset($template['uri'])) {
      // Kind of hacky, but the colspan needs to be 3 so this cell is the same
      // width as the ones that have Download, Update, and Delete.
      $options[$template['id']][] = array(
        'data' => l(t('Download'), file_create_url($template['uri'])),
        'colspan' => 3,
      );
    }
    elseif (isset($template['fid'])) {
      $options[$template['id']][] = l(t('Download'), file_create_url(file_load($template['fid'])->uri));
      $options[$template['id']][] = l(t('Update'), "admin/islandora/tools/islandora_spreadsheet_ingest/{$template['id']}/update_template");
      $options[$template['id']][] = l(t('Delete'), "admin/islandora/tools/islandora_spreadsheet_ingest/{$template['id']}/delete_template");
    }
  }
  $form['templates'] = array(
    '#theme' => 'table',
    '#caption' => t('Templates'),
    '#header' => array(
      t('Name'),
      t('Associated DSIDs'),
      array('data' => t('Operations'), 'colspan' => 3),
    ),
    '#rows' => $options,
    '#empty' => t('No templates available.'),
  );
  $form['delimiter_defaults'] = array(
    '#type' => 'fieldset',
    '#title' => t('CSV Delimiter Defaults'),
    '#collapsible' => FALSE,
    'default_cell_delimiter' => array(
      '#type' => 'textfield',
      '#title' => t('Cell Delimiter'),
      '#description' => t('Delimiter to designate cells in a row.'),
      '#default_value' => variable_get('islandora_spreadsheet_ingest_default_cell_delimiter', ','),
    ),
  );
  $form['default_binary_path'] = array(
    '#type' => 'textfield',
    '#title' => t('Default Binaries Path'),
    '#description' => t('The default path that will be referenced to find OBJ datastreams.'),
    '#default_value' => variable_get('islandora_spreadsheet_ingest_default_binary_path', ''),
    '#maxlength' => 250,
  );
  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Update Configuration'),
  );
  return $form;
}

/**
 * Validator for admin form.
 */
function islandora_spreadsheet_ingest_admin_form_validate(array &$form, array &$form_state) {
  // Invalid path shouldn't cause the form to not submit but should pop warning.
  if (!file_exists(drupal_realpath($form_state['values']['default_binary_path']))) {
    drupal_set_message(t('Failed to resolve the filepath at @path to the local filesystem; it will not be usable for ingests.', array(
      '@path' => $form_state['values']['default_binary_path'],
    )), 'warning');
  }
}

/**
 * Submit handler for admin form.
 */
function islandora_spreadsheet_ingest_admin_form_submit(array &$form, array &$form_state) {
  variable_set('islandora_spreadsheet_ingest_default_cell_delimiter', $form_state['values']['default_cell_delimiter']);
  variable_set('islandora_spreadsheet_ingest_default_binary_path', $form_state['values']['default_binary_path']);
  drupal_set_message(t('The configuration has been saved'));
}

/**
 * Add template form.
 */
function islandora_spreadsheet_ingest_add_template_form(array $form, array &$form_state) {
  $form['name'] = array(
    '#type' => 'textfield',
    '#required' => TRUE,
    '#title' => t('Template Name'),
  );
  $form['file'] = array(
    '#type' => 'managed_file',
    '#required' => TRUE,
    '#title' => t('Upload Template'),
    '#description' => t('Select a template file to upload. Must have the extension <strong>xsl</strong> or <strong>xslt</strong>. Templates are expected to contain a named xsl template node called "root" containing the root node of the output XML.'),
    '#upload_location' => file_default_scheme() . '://',
    '#upload_validators' => array(
      'file_validate_extensions' => array('xsl xslt'),
    ),
  );
  $form['dsids'] = array(
    '#type' => 'textfield',
    '#required' => TRUE,
    '#title' => t('Associated DSIDs'),
    '#description' => t('Comma-separated list of datastream IDs this template should be associated with.'),
    '#element_validate' => array('islandora_spreadsheet_ingest_validate_comma_separated_dsids'),
  );
  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Add Template'),
  );
  return $form;
}

/**
 * Add template form submit callback.
 */
function islandora_spreadsheet_ingest_add_template_form_submit(array $form, array &$form_state) {
  form_load_include($form_state, 'inc', 'islandora_spreadsheet_ingest', 'includes/db');
  $form_state['redirect'] = 'admin/islandora/tools/islandora_spreadsheet_ingest';

  try {
    $id = islandora_spreadsheet_ingest_add_template(
      $form_state['values']['name'],
      $form_state['values']['file']
    );
  }
  catch (PDOException $exception) {
    drupal_set_message(t('Please choose a unique name when adding a template.'), 'error');
    return;
  }

  $set = islandora_spreadsheet_ingest_set_dsid_associations($id, explode(',', trim($form_state['values']['dsids'])));
  if (!$set) {
    drupal_set_message(t('Failed to set the DSID associations for this template; check the logs for details.'), 'error');
  }

  $managed_file = file_load($form_state['values']['file']);
  file_usage_add($managed_file, 'islandora_spreadsheet_ingest', 'template', $id);
  drupal_set_message(t('Template added.'));
}

/**
 * Form for deleting a template.
 */
function islandora_spreadsheet_ingest_delete_template_form($form, array &$form_state, $id) {
  form_load_include($form_state, 'inc', 'islandora_spreadsheet_ingest', 'includes/utilities');
  $templates = islandora_spreadsheet_ingest_get_templates();
  foreach ($templates as $template) {
    if ($template['id'] == $id) {
      $name = $template['name'];
      break;
    }
  }
  return array(
    'template_id' => array(
      '#type' => 'hidden',
      '#value' => $id,
    ),
    'description' => array(
      '#prefix' => '<div>',
      '#markup' => t('Are you sure you want to delete the template <strong>%name</strong>? This action is irreversible.', array('%name' => $name)),
      '#suffix' => '</div>',
    ),
    'delete' => array(
      '#type' => 'submit',
      '#value' => t('Delete'),
      '#name' => 'delete',
    ),
    'cancel' => array(
      '#type' => 'submit',
      '#value' => t('Cancel'),
      '#name' => 'cancel',
    ),
  );
}

/**
 * Submit form delete callback.
 */
function islandora_spreadsheet_ingest_delete_template_form_submit(array $form, array &$form_state) {
  form_load_include($form_state, 'inc', 'islandora_spreadsheet_ingest', 'includes/db');
  $form_state['redirect'] = 'admin/islandora/tools/islandora_spreadsheet_ingest';
  if ($form_state['clicked_button']['#name'] == 'delete') {
    $id = $form_state['values']['template_id'];
    islandora_spreadsheet_ingest_purge_template($id);
    drupal_set_message(t('Successfully deleted the template.'));
  }
}

/**
 * Update template form.
 */
function islandora_spreadsheet_ingest_update_template_form(array $form, array &$form_state, $id) {
  form_load_include($form_state, 'inc', 'islandora_spreadsheet_ingest', 'includes/db');
  $template = islandora_spreadsheet_ingest_get_db_template($id);
  $form['template_id'] = array(
    '#type' => 'hidden',
    '#value' => $id,
  );
  $form['name'] = array(
    '#type' => 'textfield',
    '#required' => TRUE,
    '#title' => t('Template Name'),
    '#default_value' => $template['name'],
  );
  $form['file'] = array(
    '#type' => 'managed_file',
    '#title' => t('Upload Template'),
    '#description' => t('Select a template file to upload. Must have the extension <strong>xsl</strong> or <strong>xslt</strong>. Templates are expected to contain a named xsl template node called "root" containing the root node of the output XML.'),
    '#upload_location' => file_default_scheme() . '://',
    '#upload_validators' => array(
      'file_validate_extensions' => array('xsl xslt'),
    ),
  );
  $form['dsids'] = array(
    '#type' => 'textfield',
    '#required' => TRUE,
    '#title' => t('Associated DSIDs'),
    '#description' => t('Comma-separated list of datastream IDs this template should be associated with.'),
    '#element_validate' => array('islandora_spreadsheet_ingest_validate_comma_separated_dsids'),
    '#default_value' => implode(', ', islandora_spreadsheet_ingest_get_db_template_dsids($id)),
  );
  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Update Template'),
  );
  return $form;
}

/**
 * Update template form submit callback.
 */
function islandora_spreadsheet_ingest_update_template_form_submit(array $form, array &$form_state) {
  $form_state['redirect'] = 'admin/islandora/tools/islandora_spreadsheet_ingest';

  $old_template = islandora_spreadsheet_ingest_get_db_template($form_state['values']['template_id']);
  if ($form_state['values']['file'] && $old_template['fid'] != $form_state['values']['file']) {
    $managed_file = file_load($form_state['values']['file']);
    $old_managed_file = file_load($old_template['fid']);
    file_usage_delete($old_managed_file, 'islandora_spreadsheet_ingest', 'template', $form_state['values']['template_id']);
    file_usage_add($managed_file, 'islandora_spreadsheet_ingest', 'template', $form_state['values']['template_id']);
    file_delete($old_managed_file);
  }

  islandora_spreadsheet_ingest_update_template(
    $form_state['values']['template_id'],
    $form_state['values']['name'],
    $form_state['values']['file'] ? $form_state['values']['file'] : $old_template['fid']
  );

  $set = islandora_spreadsheet_ingest_set_dsid_associations($form_state['values']['template_id'], array_map('trim', explode(',', $form_state['values']['dsids'])));
  if (!$set) {
    drupal_set_message(t('Failed to update the DSID associations for this template; check the logs for details.'), 'error');
  }

  drupal_set_message(t('Template updated.'));
}

/**
 * Validates that comma-separated DSIDs are all valid DSIDs.
 */
function islandora_spreadsheet_ingest_validate_comma_separated_dsids($element, &$form_state, $form) {
  module_load_include('inc', 'islandora', 'includes/utilities');
  $dsids = array_map('trim', explode(',', $element['#value']));
  foreach ($dsids as $dsid) {
    if (!islandora_is_valid_dsid($dsid)) {
      form_error($element, t('@dsid is not a valid DSID', array(
        '@dsid' => $dsid,
      )));
    }
  }
}