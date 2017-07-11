<?php

/**
 * @file
 * Define how to interact with geslib files
 * Info: http://www.unleashed-technologies.com/blog/2010/07/16/drupal-6-inserting-updating-nodes-programmatically
 */

include_once dirname(__FILE__) . '/Encoding.php';

class GeslibWriter {

  /**
  * Read filename line by line
  *
  * @param $filename
  *	Geslib export file
  * @param $default_nom_collection
  * @param $default_nom_category
  *
  */
  function __construct($elements_type, $elements, $geslib_filename, $uid) {
    $this->elements_type = $elements_type;
    $this->elements = $elements;
    $this->geslib_filename = $geslib_filename;
    $this->user_uid = $uid;
  }

  /**
  * Write elements to nodes
  */
  function write_nodes() {
    $query = 'SELECT id FROM {geslib_log} WHERE component = :component AND imported_file = :file AND (status = "ok" OR status ="working")';
    $result = db_query($query, array(':component' => $this->elements_type, ':file' => $this->geslib_filename));
    if ( $result->rowCount() == 0 && $this->elements &&
         ($node_type = variable_get('geslib_'.$this->elements_type.'_node_type', NULL))) {
      $log_element = array('start_date' => time(),
                           'component' => $this->elements_type,
                           'imported_file' => basename($this->geslib_filename),
                           'uid' => $this->user_uid, 'status' => 'ok');
      if ($this->itemname == "category") {
        $this->process_element($this->elements, $this->elements_type, $node_type, true);
      } else {
        $this->process_element($this->elements, $this->elements_type, $node_type, false);
      }
      $this->flush_cache();
      $log_element['count'] = count($this->elements);
      $log_element['end_date'] = time();
      drupal_write_record('geslib_log', $log_element);
    }
  }

}
