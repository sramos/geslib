<?php

/**
 * @file
 * Define how to interact with geslib files
 * Info: http://www.unleashed-technologies.com/blog/2010/07/16/drupal-6-inserting-updating-nodes-programmatically
 *
 * @author   "Santiago Ramos" <sramos@sitiodistinto.net>
 * @package  Geslib
 */

include_once dirname(__FILE__) . '/GeslibCommon.php';

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
    $this->node_type = variable_get('geslib_'.$this->elements_type.'_node_type', NULL);
    $this->gid_field = variable_get('geslib_link_content_field', NULL);
    $this->elements = $elements;
    $this->geslib_filename = $geslib_filename;
    $this->user_uid = $uid;
  }

  /**
  * Write elements to nodes
  */
  function save_items() {
    $query = "SELECT id FROM {geslib_log} WHERE component = :component AND imported_file = :file AND status IN ('ok', 'working')";
    $result = db_query($query, array(':component' => $this->elements_type, ':file' => $this->geslib_filename));
    if ( $result->rowCount() == 0 && $this->elements && $this->node_type ) {
      $log_element = array('start_date' => time(),
                           'component' => $this->elements_type,
                           'imported_file' => basename($this->geslib_filename),
                           'uid' => $this->user_uid, 'status' => 'working');
      drupal_write_record('geslib_log', $log_element);
      if ($this->elements_type == "book" || $this->elements_type == "other") {
        $this->process_products();
      } elseif ($this->elements_type == "covers") {
        $this->process_covers();
      } else {
        $this->process_elements();
      }
      $this->flush_cache();
      $log_element['count'] = count($this->elements);
      $log_element['status'] = 'ok';
      $log_element['end_date'] = time();
      drupal_write_record('geslib_log', $log_element, 'id');
    }
  }

  /**
  * Write element to database
  */
  function process_elements() {
    $drupal_node_type = $this->node_type;
    # If true, link existing nodes with same title to geslib. If false, create node if there is no linked yet
    $use_existing_nodes = ($this->elements_type == "category");

    $counter = 0;
    $total_counter = 0;
    # Loop elements and send it to apropiate action
    GeslibCommon::vprint(t("Importing")." ".$this->elements_type, 1);
    foreach ($elements as $object_id => $object) {
      if ($counter == 1000) {
        # Not sure if this clear internal node
        #$this->flush_cache();
        $counter = 0;
      }
      if ($object["action"] != "B") {
        # If there is no action defined, try with add
        if ($object["action"] == NULL) {
          $object["action"] = "M";
        }
        # Create or update node and use it to modify parameters (linked or defined)
        $node = $this->update_object($drupal_node_type, $object_id, $object, $use_existing_nodes);
        if ( $node ) {
          # Update node attributes, relationships and taxonomy terms
          $this->update_attributes($node, $object);
          $this->update_relationships($node, $drupal_node_type, $object);
          // Remove object from memory
          $node = NULL;
        } else {
          GeslibCommon::vprint(t("Node @type with GeslibID @gid doesn't exist", array('@type' => $drupal_node_type, '@gid' => $object_id)), 0);
        }
      } else {
        $this->delete_object($drupal_node_type, $object_id);
      }
      $counter += 1;
      $total_counter += 1;
    }
    return $total_counter;
  }

  /**
  * Write products (books and other) to database
  */
  function process_products() {
    $drupal_node_type = $this->node_type;
    # If true, link existing nodes with same title to geslib. If false, create node if there is no linked yet
    $use_existing_nodes = ($this->elements_type == "category");

    $counter = 0;
    $total_counter = 0;
    $geslib_book_type = variable_get('geslib_book_geslib_type', NULL);
    # Loop elements and send it to apropiate action
    GeslibCommon::vprint(t("Importing Products"),1);
    foreach ($elements as $object_id => $object) {
      # Each 1000 objects, clear cache
      if ( $counter == 1000 ) {
        # Not sure if this clear internal node cache
        #$this->flush_cache();
        $counter = 0;
      }
    }
  }

  /**
  * Process covers
  */
  function process_covers() {
    $counter = 0;
    $total_counter = 0;
    $geslib_book_type = variable_get('geslib_book_geslib_type', NULL);
    $geslib_book_node_type = variable_get('geslib_book_node_type', NULL);
    # Loop elements and send it to apropiate action
    GeslibCommon::vprint(t("Searching book covers"),1);
    foreach ($elements as $object_id => $object) {
      # Each 1000 objects, clear cache
      if ( $counter == 1000 ) {
        # Not sure if this clear internal node cache
        #$this->flush_cache();
        $counter = 0;
      }
      if ( $object["action"] != "B" && $object["type"] == $geslib_book_type && $object["attribute"]["ean"] && $this->get_uploaded_book_image($object["attribute"]["ean"]) ) {
        $node = $this->get_node_by_gid($object_id, "book", $geslib_book_node_type);
        # If there is no cover, we define it
        if ($node->nid) {
          GeslibCommon::vprint(t("Updating")." ".$node_type." '".$object["title"]."' (NID:".$node->nid."/GESLIB_ID:".$object_id."/TITLE:'".$node->title."')", 1);
          $this->set_object_image($node, $object["*cover_url"]);
        }
        $counter += 1;
        $total_counter += 1;
      }
      $node = NULL;
    }
    return $total_counter;
  }

  /**
  * Insert or modify simple object
  *
  * @param node_type
  *   drupal node type
  * @param object_id
  *   geslib object_id
  * @param object
  *   object properties
  * @param use_existing_nodes
  *   If true, link existing nodes with same title to geslib. If false, create node if there is no linked yet
  */
  function update_object($node_type, $object_id, &$object, $use_existing_nodes) {
    $new_element = false;
    # Get node if exists
    $node = $this->get_node_by_gid($object_id, $node_type);
    # If node exists, only gets authorization for update
    if ( $node ) {
      print_r("ESTAMOS CON: ");
      print_r($node);
    # Return NULL if doesn't exists and there is no ADD or MODIFY action
    } elseif ( $object["action"] != "A" && $object["action"] != "M" ) {
      return NULL;
    # Si no hay nodo vinculado al gid...
    # If that node doesn't exist
    } else {
        $node = NULL;
        $new_element = true;
    }
    #$node->save();
    return $node;
  }

  /**
   * Get Node by GeslibID
   *
   * @param geslib_id
   *    geslib object_id
   * @param geslib_type
   *    geslib type of object
   * @param node_type
   *    drupal node type
   *
   */
   function get_node_by_gid($geslib_id, $node_type) {
     $nid = $this->get_nid_by_gid($geslib_id, $node_type);
     # If there is a node with that gid, load it
     if ($nid) {
       $node = entity_load('node', array($nid));
     } else {
       $node = NULL;
     }
     return $node;
   }
   /**
   * Get NodeID by GeslibID
   *
   * @param geslib_id
   *    geslib object_id
   * @param geslib_type
   *    geslib type of object
   * @param node_type
   *    drupal node type
   *
   */
   function get_nid_by_gid($geslib_id, $node_type) {
     $query = new EntityFieldQuery();
     $query->entityCondition('entity_type', 'node')
           ->entityCondition('bundle', $node_type)
           ->fieldCondition($this->gid_field, 'value', $geslib_id, '=')
           ->range(0, 1)
           ->addMetaData('account', user_load($this->user_uid));
     $result = $query->execute();
     # If there is any result, return nodeid
     if (isset($result['node'])) {
       $nids = array_keys($result['node']);
       $nid = $nids[0];
     } else {
       print_r("No tenemos resultados\n");
       $nid = NULL;
     }
     # Nullify variables to force garbage collection
     $query = NULL;
     $result = NULL;
     return $nid;
   }

  /**
  * Flush all caches
  */
  function flush_cache() {
    GeslibCommon::vprint("\n---------------------- ".t("Flush all caches")."\n",2);
    drupal_flush_all_caches();
  }
}
