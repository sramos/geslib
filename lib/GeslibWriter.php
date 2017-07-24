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
    # If true, link existing nodes with same title to geslib. If false, create node if there is no linked yet
    $use_existing_nodes = ($this->elements_type == "category");

    $counter = 0;
    $total_counter = 0;
    # Loop elements and send it to apropiate action
    GeslibCommon::vprint(t("Importing")." ".$this->elements_type, 1);
    foreach ($this->elements as $object_id => $object) {
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
        $node = $this->update_object($object_id, $object, $use_existing_nodes);
        if ( $node ) {
          # Update node attributes, relationships and taxonomy terms
          $this->update_attributes($node, $object);
          $this->update_relationships($node, $object);
          // Remove object from memory
          $node = NULL;
        } else {
          GeslibCommon::vprint(t("Node @type with GeslibID @gid doesn't exist", array('@type' => $this->node_type, '@gid' => $object_id)), 0);
        }
      } else {
        $this->delete_object($this->node_type, $object_id);
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
  function update_object($object_id, &$object, $use_existing_nodes) {
    $new_element = false;
    # Get node if exists
    $node = $this->get_node_by_gid($object_id, $this->node_type);
    # If node exists, only gets authorization for update
    if ( $node ) {
    # Return NULL if doesn't exists and there is no ADD or MODIFY action
      print_r("Tenemos nodo!!!\n");
    } elseif ( $object["action"] != "A" && $object["action"] != "M" ) {
      return NULL;
    # Si no hay nodo vinculado al gid...
    # If that node doesn't exist
    } else {
      print_r("No hemos encontrado el nodo... lo creamos\n");
      $node = $this->create_empty_node($object_id);
    }

    # Basic node data
    $node->uid = $this->user_uid;
    $node->name = "admin";
    $node->status = 1;
    # Title
    if (array_key_exists('title', $object)) {
      $node->title = $object['title'];
    }

    # Check that node is ready and save it
    if ($node = node_submit($node)) {
      node_save($node);
      GeslibCommon::vprint(t("Node")." '".$node->title."' (NID:".$node->nid."/GID:".$object_id.") ".t("updated correctly"), 2);
      return $node;
    } else {
      GeslibCommon::vprint(t("Node")." '".$node->title."' (NID:".$node->nid."/GID:".$object_id.") ".t("processed incorrectly"), 0);
      return NULL;
    }
  }

  /**
  * Create empty node
  *
  * @param geslib_id
  *    geslib object_id
  */
  function create_empty_node($geslib_id) {
    $gid_field = $this->gid_field;
    $node = new stdClass();
    $node->type = $this->node_type;
    $node->$gid_field = array('und' => array( array('value' => $geslib_id) ));;
    $node->language = 'es';
    $node->promote = 0; // Display on front page ? 1 : 0
    $node->sticky = 0;  // Display top of page ? 1 : 0
    $node->format = 1;  // 1:Filtered HTML, 2: Full HTML, 3: ???
    $node->comment = variable_get('comment_'.$node_type, 0); // 0:Disabled, 1:Read, 2:Read/Write
    node_object_prepare($node);
    return $node;
  }

  /**
  * Update node fields
  *
  * @param node
  *    Node object reference
  * @param object_data
  *    Node attributes
  */
  function update_attributes($node, $object_data) {
    if ($node->nid && ($attributes = $object['attribute'])) {
      # Recoge si el nodo ha cambiado
      $changed = false;
      # Elimina los atributos restringidos
      $bad_keys = array('action','title','type');
      $good_data = array_diff_key($attributes,array_flip($bad_keys));

      # Recorre el resto de atributos actualizando la info
      foreach ($good_data as $attr_name => $attr_value) {
        // Format 5: plaintext
        if ($this->change_attribute($node, $attr_name, $attr_value, 5)) {
          $changed = true;
        }
      }
      # Check that node changed
      if ($changed) {
        # If and is ready and it can be saved
        if ($node = node_submit($node)) {
          node_save($node);
          GeslibCommon::vprint(t("Node")." '".$node->title."' (NID:".$node->nid."/GID:".$object_id.") ".t("attributes updated correctly"), 2);
          return $node;
        } else {
          GeslibCommon::vprint(t("Node")." '".$node->title."' (NID:".$node->nid."/GID:".$object_id.") ".t("attributes processed incorrectly"), 0);
          return NULL;
        }
      }
    }
  }

  /**
  * Change node field
  *
  * @param &node
  *    Reference to node object
  * @param attr_name
  *    Attribute name
  * @param attr_value
  *    Attribute value
  * @param attr_format (optiona)
  *    Attribute format
  */
  function change_attribute(&$node, $attr_name, $attr_value, $attr_format=NULL) {
    $changed = false;
    # El body siempre lo mapeamos igual
    if ($attr_name == 'body') {
      $field_name = 'body';
    } else {
      # Obtenemos el campo a mapear de la configuracion del modulo
      $field_name = variable_get('geslib_'.$this->elements_type.'_attribute_'.$attr_name, NULL);
    }

    # Si existe mapeo para el campo, lo actualiza
    if ($field_name) {
      # Si hemos dicho que esta vinculado a una taxonomia
      if (strpos($field_name, "#vid-") === 0) {
        $vid = substr($field_name,5);
        # Pide actualizar el vocabulario
        $this->update_vocabulary_terms($node,$vid,$attr_value);
      # El resto de atributos los actualizamos de forma normal
      } else {
        # Construimos un array de valores
        $field_values = array();
        # Si los valores ya son un array los cargaremos de ahi
        if (is_array($attr_value)) {
          $attr_values = $attr_value;
        # En caso contrario, construimos un array de un solo valor
        } else {
          $attr_values = array($attr_value);
        }
        # Recorremos el array de todos los valores
        foreach ( $attr_values as $attr_element ) {
          # construimos el elemento
          $field_value = array( 'value' => $attr_element );
          if ($attr_format) {
            $field_value['format'] = $attr_format;
          }
          # asignamos el valor al array de campos
          $field_values[] = $field_value;
        }
        # y lo asociamos al nodo
        $node->$field_name = array('und' => $field_values);
      }
      $changed = true;
    }
    return $changed;
  }

  /**
  * Update vocabulary terms
  *
  * @node
  *   drupal node
  * @vid
  *   vocabulary ID
  * @param values
  *   object terms separated by comma
  */
  function update_vocabulary_terms(&$node, $vid, $values) {
    if ($values && $values != "") {
      GeslibCommon::vprint(t("Updating vocabulary").": ".$values);
      $terms = array();
      $terms['tags'] = array($vid => $values);
      taxonomy_node_save($node, $terms);
      # Another way to do it
      /*
      foreach (explode(",", $values) as $term) {
        $new_term = array('name' => $term, 'parent' => 0, 'vid' => $vid);
        taxonomy_save_term($autoterm);
      }
      */
    }
  }

  /**
  * Update relationships
  *
  * @param node
  *   reference to drupal node
  * @param object
  *   object properties
  */
  function update_relationships(&$node, &$object) {
    if ($node->nid && ($relations = $object['relation'])) {
      $updated = false;
      # Allowed node fields for that object
      $allowed = field_info_instances('node', $this->node_type);

      # Loop all relation array
      foreach ( $relations as $rel_name => $rel_values ) {
        $field_name = variable_get('geslib_'.$this->elements_type.'_link_to_'.$rel_name, NULL);
        # If there is any relation defined and it's any of the allowed fields
        if ($field_name && $allowed[$field_name]) {
          GeslibCommon::vprint("Actualizando relaciones con ".$rel_name,2);
          # Array used to store relation
          $linked_elements = array();
          # Loop all related values (it could be multivalued)
          foreach ( $rel_values as $rel_element ) {
            # If related element has node type, we can search it
            if ( $rel_node_type = variable_get('geslib_'.$rel_name.'_node_type', NULL) ) {
              # If relation is about authors, and link_only_authors is true, and is not an author, ignore it
              if ( $rel_name == "author" && variable_get('geslib_book_link_only_authors', NULL) && $rel_element["function"] != "A") {
                GeslibCommon::vprint(t("Ignoring author relationship. The type is:") . " " . $rel_element["function"]);
              } else {
                $linked_nid = $this->get_nid_by_gid($rel_element["gid"], $rel_node_type);
                # If related object exists, link it
                if ($linked_nid) {
                  GeslibCommon::vprint("Guardando la referencia a ".$rel_node_type." ('".$field_name."/NID:".$linked_nid."/GID:".$rel_element["gid"].")");
                  # If related node field is nodereference, we store only related object nid
                  if (preg_match('#^nodereference#', $allowed[$field_name]['widget_type']) === 1) {
                    $linked_elements[] = array( 'nid' => $linked_nid );
                  # For text fields, store the relation title value
                  } else {
                    $linked = $this->get_node_by_gid($rel_element["gid"], $rel_name, $rel_node_type);
                    $linked_elements[] = array( 'value' => $linked->title );
                    $linked = NULL;
                  }
                } else {
                  GeslibCommon::vprint("ERROR: El nodo ". $rel_node_type ." relacionado (GID:". $rel_element["gid"] .") no pudo encontrarse",0);
                }
              }
              # If related element could not be found, look it in geslib array
            } else if (preg_match('#^nodereference#', $allowed[$field_name]['widget_type']) !== 1) {
              $referenced_value = $this->elements[$rel_name][$rel_element["gid"]]["title"];
              if ( $referenced_value ) {
                $linked_elements[] = array( 'value' => $referenced_value );
                GeslibCommon::vprint("Guardando el valor ('".$referenced_value."'/GID:".$rel_element["gid"].") como ".$field_name);
              } else {
                GeslibCommon::vprint("ERROR: No pudo encontrarse el valor de la referencia en el fichero geslib (".$rel_name."/GID:".$rel_element["gid"].")",0);
              }
            } else {
              GeslibCommon::vprint("ERROR: No se que hacer con el elemento relacionado",0);
            }
          }
          # Store relationship in associated node field
          $node->$field_name = $linked_elements;
          $linked_elements = NULL;
          $updated = true;
        }
      }
      # Check that node is ready to save
      if ($updated) {
        if ($node_to_save = node_submit($node)) {
          node_save($node_to_save);
          GeslibCommon::vprint(t("Node")." '".$node_to_save->title."' (NID:".$node_to_save->nid."): ".t("relationships updated correctly"),2);
        } else {
          #print_r($node);
          GeslibCommon::vprint(t("Relationships for node")." '".$node->title."' (NID:".$node->nid.") ".t("not updated"), 0);
        }
      } else {
        GeslibCommon::vprint(t("There is no relationships to update"));
      }
      $node_to_save = NULL;
    }
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
     $nodes = array();
     # If there is a node with that gid, load it
     if ($nid) {
       $nodes = entity_load('node', array($nid));
     }
     return reset($nodes);
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
