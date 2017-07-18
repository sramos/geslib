<?php

include_once dirname(__FILE__) . '/geslib.api.inc';

/**
 * @file
 * Drush commands for Geslib.
 */

/**
 * Implementation of hook_drush_help().
 */
function geslib_drush_help($section) {
  switch ($section) {
    case 'drush:geslib-import':
      return "\nGESLIB-IMPORT\n".dt('Import geslib files (prepare + load).');
    case 'drush:geslib-covers':
      return "\nGESLIB-COVERS\n".dt('Search for covers in cover directory path and upload to node book.');
  }
}

/**
 * Implementation of hook_drush_command().
 */
function geslib_drush_command() {
  $items['geslib-import'] = array(
    'callback' => 'geslib_drush_import',
    'arguments' => array(
      'geslib_file' => dt('Geslib file or path with INTER files to import'),
      'verbose_level' => dt('Verbose level. 0:silent, 1:errors, ... 4:debug (Default 1)'),
    ),
    'examples' => array(
      'Standard example' => 'drush --user=1 geslib-import /tmp/INTER001',
      'Path example'     => 'drush --user=1 geslib-import /tmp',
      'Verbose example'  => 'drush --user=1 geslib-import /tmp/INTER001 3',
    ),
    'description' => dt('Import products from a geslib file.'),
    'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_LOGIN,
  );
  $items['geslib-covers'] = array(
    'callback' => 'geslib_drush_covers',
    'arguments' => array(
      'geslib_file' => dt('Geslib file or path with INTER files to check book covers'),
      'verbose_level' => dt('Verbose level. 0:silent, 1:errors, ... 4:debug (Default 1)'),
    ),
    'examples' => array(
      'Standard example' => 'drush --user=1 geslib-covers /tmp/INTER001',
      'Path example'     => 'drush --user=1 geslib-covers /tmp',
      'Verbose example'  => 'drush --user=1 geslib-covers /tmp/INTER001 3',
    ),
    'description' => dt('Search book covers for products from a geslib file.'),
    'bootstrap' => DRUSH_BOOTSTRAP_DRUPAL_LOGIN,
  );

  return $items;
}

/**
 * Import book covers from books of a geslib file (callback)
 */
function geslib_drush_covers($geslib_file, $verbose_level=1) {
  try {
    $reader = new geslib_api($geslib_file,$verbose_level);
    drush_print(dt('Initializing'));
    $files = $reader->get_pending_files();
    foreach($files as $file) {
      drush_print('');
      drush_print(dt('Reading Geslib file') ." ". $file);
      $reader->read_file($file);
      drush_log(dt('Geslib file readed'), 'ok');
      drush_print(dt('Importing covers for books from Geslib file'));
      $reader->import_covers();
      drush_log(dt('Covers from Geslib books imported sucessfully from') ." ". $file, 'ok');
    }
  } catch(Exception $e) {
    drush_log(dt($e->getMessage()), 'error');
  }
}

/**
 * Import products from a geslib file (callback)
 */
function geslib_drush_import($geslib_file, $verbose_level=1) {
  try {
    $reader = new geslib_api($geslib_file,$verbose_level);
    drush_print(dt('Initializing'));
    $files = $reader->get_pending_files();
    foreach($files as $file) {
      drush_print('');
      drush_print(dt('Reading Geslib file') ." ". $file);
      $reader->read_file($file);
      drush_log(dt('Geslib file readed'), 'ok');
      drush_print(dt('Importing elements from Geslib file'));
      $reader->import_elements();
      drush_log(dt('Geslib elements imported sucessfully from') ." ". $file, 'ok');
    }
  } catch(Exception $e) {
    drush_log(dt($e->getMessage()), 'error');
  }
}
