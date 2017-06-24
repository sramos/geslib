<?php

/**
 * Carga el fichero geslib en una tabala de la base de datos
 * para poder procesarlo más tarde
 * 
 * @param type $geslib_file_path
 * @param type $context
 */
function geslib_process_read_file($geslib_file_path, &$context) {
  $geslib_file = file_get_contents($geslib_file_path);
  $geslib_file_array = explode("\n", $geslib_file);
  if (!isset($context['sandbox']['progress'])) {
    $context['sandbox']['progress'] = 0;
    $context['sandbox']['max'] = count($geslib_file_array);
    $context['results']['processed_lines'] = $context['sandbox']['max'] ;
    $context['results']['start_date'] = REQUEST_TIME;
    $context['results']['inter_file'] = substr($geslib_file_path, strrpos($geslib_file_path, '/') +1);
  }
  
  $limit = count($geslib_file_array) < 1000 ? count($geslib_file_array): 1000;
  
  $i = 0;
  $geslib_lines = array();
  while($i < $limit) {
    $line = explode("|", $geslib_file_array[$i]);
    geslib_process_line($line, $geslib_lines);
    unset($geslib_file_array[$i]);
    $i++;
  }
  
  foreach ($geslib_lines as $line) {
    if (!isset($line['action'])) {
      $product_db = db_select('geslib_lines', 'g')->condition('geslib_id', $line['geslib_id'])
        ->condition('type', 'product')->fields('g')->execute()->fetchObject();
      $product = unserialize($product_db->content);
      
      if ($line['type'] == 'product_author') {
        $product['authors'][] = $line;
      }
      if ($line['type'] == 'description') {
        $product['description'] = $line;
      }
      $product_db->content = serialize($product);
      drupal_write_record('geslib_lines', $product_db, array('id'));
    }
    elseif ($line['action'] != 'delete') {
      $record = array();
      $record['geslib_id'] = $line['geslib_id'];
      $record['type'] = $line['type'];
      $record['inter_file'] = $context['results']['inter_file'];
      $record['content'] = serialize($line);

      try {
        drupal_write_record('geslib_lines', $record);
      }
      catch (Exception $e){
        watchdog('geslib', "Geslib id: @id Type: @type Message: @message", array('@id' => $record['geslib_id'], '@type' => $record['type'], '@message' => $e->getMessage()));
      }
    }
  }
  
  $context['sandbox']['progress'] += $i;
  
  $context['message'] = t("Processed @progress of @max lines", array('@progress' => $context['sandbox']['progress'], '@max' => $context['sandbox']['max']));
  
  file_put_contents($geslib_file_path, implode("\n", $geslib_file_array));
  
  if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
  }
}

/**
 * Generic function to process items in the db
 * 
 * @param type $type
 * @param type $context
 */
function geslib_process_items($type, $limit, &$context){
  if (!isset($context['sandbox']['progress'])) {
    $items_db = db_select('geslib_lines', 'g')->condition('type', $type)
      ->fields('g')->execute();
    $context['sandbox']['progress'] = 0;
    $context['sandbox']['max'] =  $items_db->rowCount();
    $context['results']['items'][$type] = 0;
  }
  
  $lines = db_select('geslib_lines', 'g')->condition('type', $type)->fields('g')
    ->range(0, $limit)->execute()->fetchAll();
  
  foreach ($lines as $line) {
    $item = unserialize($line->content);
    module_invoke_all('geslib_'.$type, $item['action'], $item);
    db_delete('geslib_lines')->condition('geslib_id', $line->geslib_id)->condition('type', $type)
     ->execute();
    $context['results']['items'][$type]++;
    $context['sandbox']['progress']++;
  }
  
  $context['message'] = t("Processed @progress of @max @type items", array('@progress' => $context['sandbox']['progress'], '@max' => $context['sandbox']['max'], '@type' => $type));
  
  if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
  }
}

/**
 * Finish callback. Record log of the operations
 * 
 * @global type $user
 * @param type $success
 * @param type $results
 * @param type $operations
 */
function geslib_process_finish($success, $results, $operations) {
  if ($success) {
    $total_items = 0;
    foreach ($results['items'] as $type => $count) {
      drupal_set_message(t("Created @count @type items", array('@count' => $count, '@type' => $type)));
      $total_items += $count;
    }
    $log = array(
      'start_date' => $results['start_date'],
      'end_date' => time(),
      'imported_file' => $results['inter_file'],
      'items' => $total_items,
      'processed_lines' => $results['processed_lines'],
    );
    drupal_write_record('geslib_log', $log);
  }
  else {
    // An error occurred.
    // $operations contains the operations that remained unprocessed.
    $error_operation = reset($operations);
    $message = t('An error occurred while processing %error_operation with arguments: @arguments', array('%error_operation' => $error_operation[0], '@arguments' => print_r($error_operation[1], TRUE)));
    drupal_set_message($message, 'error');
  }
}

/***********************************************************
  DELETE FUNCTIONS
 **********************************************************/

/**
 * Delete all the taxonomies
 * @param type $context
 */
function geslib_process_delete_categories(&$context) {
  if (!isset($context['sandbox']['progress'])) {
    $terms = db_select('taxonomy_term_data', 't')->fields('t')->execute();
    $context['sandbox']['progress'] = 0;
    $context['sandbox']['max'] = $terms->rowCount();
  }
  
  $limit = 900;
  
  $terms = db_select('taxonomy_term_data', 't')->fields('t')->range(0, $limit)->execute()->fetchAll();
  
  foreach ($terms as $term) {
    taxonomy_term_delete($term->tid);
    $context['sandbox']['progress']++;
  }
  
  $context['message'] = t("Deleted @progress of @max categories", array('@progress' => $context['sandbox']['progress'], '@max' => $context['sandbox']['max']));
  
  
  if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
  }
  
}

/**
 * Delete all the products
 * @param type $context
 */
function geslib_process_delete_products(&$context) {
  if (!isset($context['sandbox']['progress'])) {
    $products = db_select('commerce_product', 'p')->fields('p')->execute();
    $context['sandbox']['progress'] = 0;
    $context['sandbox']['max'] = $products->rowCount();
  }
  
  $limit = 100;
  
  $products = db_select('commerce_product', 'p')->fields('p')->range(0, $limit)->execute()->fetchAll();
  
  foreach ($products as $product){
    commerce_product_delete($product->product_id);
    $context['sandbox']['progress']++;
  }
  
  $context['message'] = t("Deleted @progress of @max products", array('@progress' => $context['sandbox']['progress'], '@max' => $context['sandbox']['max']));
    
  if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
  }
  
}

/**
 * Delete all the nodes
 * @param type $context
 */
function geslib_process_delete_nodes(&$context){
  if (!isset($context['sandbox']['progress'])) {
    $nodes_db = db_select('node', 'n')->fields('n')->condition('type', 'libro')->execute();
    $context['sandbox']['progress'] = 0;
    $context['sandbox']['max'] = $nodes_db->rowCount();
  }
  
  $limit = 500;

  $nodes = db_select('node', 'n')->fields('n')->condition('type', 'libro')->range(0, $limit)->execute()->fetchAll();
  
  foreach($nodes as $node) {
    node_delete($node->nid);
    $context['sandbox']['progress'] ++;
  }
  
  $context['message'] = t("Deleted @progress of @max nodes", array('@progress' => $context['sandbox']['progress'], '@max' => $context['sandbox']['max']));
  if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
  }
}