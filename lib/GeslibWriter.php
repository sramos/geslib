<?php

/**
 * @file
 * Define how to interact with geslib files
 * Info: http://timonweb.com/posts/how-to-programmatically-create-nodes-comments-and-taxonomies-in-drupal-7/
 *
 * @author   "Santiago Ramos" <sramos@sitiodistinto.net>
 * @package  Geslib
 */

include_once dirname(__FILE__) . '/GeslibCommon.php';
include_once dirname(__FILE__) . '/GeslibCovers.php';

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
  function __construct($elements_type, &$elements, $geslib_filename, $user) {
    $this->elements_type = $elements_type;
    $this->node_type = variable_get('geslib_'.$this->elements_type.'_node_type', NULL);
    $this->gid_field = variable_get('geslib_link_content_field', NULL);
    $this->elements = $elements;
    $this->geslib_filename = $geslib_filename;
    $this->user = $user;
  }

  /**
  * Write elements to nodes
  */
  function save_items() {
    $query = "SELECT id FROM {geslib_log} WHERE component = :component AND imported_file = :file AND status IN ('ok', 'working')";
    $result = db_query($query, array(':component' => $this->elements_type, ':file' => $this->geslib_filename));
    if ( $result->rowCount() == 0 && $this->elements[$this->elements_type] && $this->node_type ) {
      $log_element = array('start_date' => time(),
                           'component' => $this->elements_type,
                           'imported_file' => basename($this->geslib_filename),
                           'uid' => $this->user->uid, 'status' => 'working');
      drupal_write_record('geslib_log', $log_element);
      if ($this->elements_type == "covers") {
        $this->process_covers();
      } else {
        $this->process_elements();
      }
      $this->flush_cache();
      $log_element['count'] = count($this->elements[$this->elements_type]);
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
    foreach ($this->elements[$this->elements_type] as $object_id => $object) {
      if ($counter == 1000) {
        # Not sure if this clear internal node
        #$this->flush_cache();
        $counter = 0;
      }
      if ($object["action"] != "B") {
        # If there is no action defined, try with add
        if ($object["action"] == NULL) {
          $object["action"] = "STOCK";
        }
        # Create or update node and use it to modify parameters (linked or defined)
        $node = $this->update_object($object_id, $object, $use_existing_nodes);
        if ( $node ) {
          # Update body
          if ( variable_get('geslib_' . $this->elements_type . '_body_from', NULL) ) {
            $this->update_body($node, $object, variable_get('geslib_' . $this->elements_type . '_body_from', NULL));
          }
          # Update node attributes, relationships and taxonomy terms
          $this->update_attributes($node, $object);
          $this->update_uc_attributes($node, $object);
          $this->update_relationships($node, $object);
          $this->update_object_image($node, $object);
          $this->update_object_attachment($node, $object);
          // Remove object from memory
          $node = NULL;
        } else {
          GeslibCommon::vprint(t("Node @type with GeslibID @gid doesn't exist", array('@type' => $this->node_type, '@gid' => $object_id)), 0);
        }
      } else {
        $this->delete_object($object_id);
      }
      $counter += 1;
      $total_counter += 1;
    }
    return $total_counter;
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
    foreach ($this->elements[$this->elements_type] as $object_id => $object) {
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
      #print_r("Tenemos nodo!!!\n");
      $this->get_access($node, "update");
    # If that node doesn't exist
    } elseif ( $object["action"] == "A" || $object["action"] == "M" ) {
      # print_r("No hemos encontrado el nodo... lo creamos\n");
      $node = $this->create_empty_node($object_id);
    # Return NULL if doesn't exists and there is no ADD or MODIFY action
    } else {
      return NULL;
    }

    # Basic node data
    $node->uid = $this->user->uid;
    $node->name = "admin";
    $node->status = 1;
    # Title
    if (array_key_exists('title', $object)) {
      $node->title = substr($object['title'],0,128);
      #$node->title = mb_substr($object['title'],0,128,'UTF-8');
    }

    # Check that node is ready and save it
    if (!empty($node->title) && ($node = node_submit($node))) {
      node_save($node);
      GeslibCommon::vprint(t("Node")." '".$node->title."' (NID:".$node->nid."/GID:".$object_id.") ".t("updated correctly"), 2);
      return $node;
    } else {
      GeslibCommon::vprint(t("Node")." '".$node->title."' (NID:".$node->nid."/GID:".$object_id.") ".t("processed incorrectly"), 0);
      return NULL;
    }
  }

  /**
  * Delete object if exists
  *
  * @param object_id
  *   geslib object_id
  */
  function delete_object($object_id) {
    $ret = false;
    # Get node if exists
    $node = $this->get_node_by_gid($object_id, $this->node_type);
    if ($node) {
      #print_r("Tenemos nodo!!!\n");
      $this->get_access($node, "delete");
      node_delete($node->nid);
      GeslibCommon::vprint(t("Node")." '".$node->title."' (NID:".$node->nid."/GID:".$object_id.") ".t("removed"), 2);
      $ret = true;
    }
    $node = NULL;
    return $ret;
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
    $node->is_new = true;
    node_object_prepare($node);
    $this->get_access($node, "create");
    return $node;
  }

  /**
  * Updates body for books
  *
  * @param node
  *   node to be updated
  * @param object
  *   object element
  */
  function update_body(&$node, $object, $body_from) {
    if ( $object["relation"][$body_from] ) {
      $body_gid = $object["relation"][$body_from][0]["gid"];
      $tmp_body = $this->elements[$body_from][$body_gid]["title"];
      #$tmp_body = $this->elements[$body_from][$body_gid]["attribute"]["body"];
      if ($tmp_body) {
        # Guardamos el body en full_html (format: 2)
        $node->body['und'][0] = array('value' => $tmp_body, 'format' => 2);
        # Check that node is ready and save it
        if ($node = node_submit($node)) {
          node_save($node);
          GeslibCommon::vprint(t("Updated body product"),2);
        } else {
          GeslibCommon::vprint(t("Body for node")." '".$node->title."' (NID:".$node->nid."/GID:".$object_id.") ".t("processed incorrectly"), 0);
        }
      }
    }
  }

  /**
  * Update node fields
  *
  * @param node
  *    Node object reference
  * @param object_data
  *    Node attributes reference
  */
  function update_attributes(&$node, $object_data) {
    if ($node->nid && ($attributes = $object_data['attribute'])) {
      # Recoge si el nodo ha cambiado
      $changed = false;
      # Elimina los atributos restringidos
      $bad_keys = array('action','title','type');
      $good_data = array_diff_key($attributes,array_flip($bad_keys));

      # Variable para almacenar las taxonomias multivaluadas ya usadas
      $taxonomy_fields_used = array();
      # Recorre el resto de atributos actualizando la info
      foreach ($good_data as $attr_name => $attr_value) {
        # Averigua el nombre mapeado del campo
        # El body siempre sera igual
        if ($attr_name == 'body') {
          $field_name = 'body';
        } else {
          # Obtenemos el campo a mapear de la configuracion del modulo
          $field_name = variable_get('geslib_'.$this->elements_type.'_attribute_'.$attr_name, NULL);
        }
        # Si tenemos campo mapeado...
        if ($field_name) {
          # Si el campo corresponde a una taxonomia
          if (strpos($field_name, "#tax-") === 0) {
            $tax_field_name = substr($field_name,5);
            # Si el campo de enlace no ha sido usado aun, lo limpia
            if (!in_array($tax_field_name,$taxonomy_fields_used)) {
              $node->{$tax_field_name}['und'] = array();
              $taxonomy_fields_used[] = $tax_field_name;
            }
            # Obtiene el vid desde el primer vocabulario permitido para el campo
            $info = field_info_field($tax_field_name);
            $vocab = taxonomy_vocabulary_machine_name_load($info['settings']['allowed_values'][0]['vocabulary']);
            # Pide actualizar el vocabulario
            $this->update_vocabulary_terms($node,$tax_field_name,$vocab->vid,$attr_value);
          // El resto de campos, los guarda como atributos
          } else {
            // Format 5: plaintext
            $this->change_attribute($node, $field_name, $attr_value, 5);
          }
          $changed = true;
        }
      }
      # Check that node changed
      if ($changed) {
        # If and is ready and it can be saved
        if ($node = node_submit($node)) {
          node_save($node);
          GeslibCommon::vprint(t("Node")." '".$node->title."' (NID:".$node->nid.") ".t("attributes updated correctly"), 2);
          return $node;
        } else {
          GeslibCommon::vprint(t("Node")." '".$node->title."' (NID:".$node->nid.") ".t("attributes processed incorrectly"), 0);
          return NULL;
        }
      }
    }
  }

  /**
  * Update ubercart node fields
  *
  * @param node
  *    Node object reference
  * @param object_data
  *    Ubercart node attributes
  */
  function update_uc_attributes(&$node, $object_data) {
    if ($node->nid && ($attributes = $object_data['uc_product'])) {
      # Recoge si el nodo ha cambiado
      $changed = false;

      # Primero comprueba si se esta modificando el stock
      # y si es asi lo deja para lo ultimo
      if (array_key_exists('stock',$attributes)) {
        $stock = $attributes['stock'];
        unset($attributes['stock']);
      }
      # Recorre los atributos UC actualizando la info
      foreach ($attributes as $attr_name => $attr_value) {
        # Hace un procesado especial del stock para ajustar tambien
        # si el producto es ordenable o no
        $node->$attr_name = $attr_value;
        $changed = true;
      }
      # Despues de cambiar todo, modifica el stock
      if ( $stock !== NULL ) {
        # Por defecto, tiene el valor "0"
        if (empty($stock)) {
          $attr_value = 0;
        } else {
          $attr_value = $stock;
        }
        # Cambia el valor del stock
        uc_stock_set($node->model, $attr_value);
        # Hace un procesado especial del stock para ajustar tambien
        # si el producto se puede coger o no
        $node->qty = $attr_value;
        $node->ordering = ( $attr_value == 0 ? 0 : 1 );
        $changed = true;
      }
      # Check that node changed
      if ($changed) {
        # If and is ready and it can be saved
        if ($node = node_submit($node)) {
          node_save($node);
          GeslibCommon::vprint(t("Node")." '".$node->title."' (NID:".$node->nid.") ".t("ubercart attributes updated correctly"), 2);
          return $node;
        } else {
          GeslibCommon::vprint(t("Node")." '".$node->title."' (NID:".$node->nid.") ".t("ubercart attributes processed incorrectly"), 0);
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
  function change_attribute(&$node, $field_name, $attr_value, $attr_format=NULL) {
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
      # Las cadenas vacias las asociamos a NULL
      if ( $attr_element == '' ) {
        $attr_element = NULL;
      } else {
        # Preformateamos segun tipo de campo
        #$field_type = field_info_field($field_name)['type'];
        #if ($field_type == 'datetime' && !empty($attr_element->date)) {
        #  $attr_element = new DateTime($attr_element);
        #}
      }
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

  /**
  * Update vocabulary terms
  *
  * @param node
  *   reference to drupal node
  * @param field_name
  *   node field name for taxonomy reference
  * @param vid
  *   vocabulary ID
  * @param values
  *   object terms separated by comma
  */
  function update_vocabulary_terms(&$node, $field_name, $vid, $values) {
    if (!empty($values)) {
      GeslibCommon::vprint(t("Updating vocabulary")." ".$field_name.": ".$values, 2);
      # Recorremos todos los valores
      foreach ( explode(",",$values) as $value ) {
        # Primero buscamos el termino en la taxonomia
        $tid = $this->get_tid_by_name($vid, $value);
        # y lo incluimos si no existe
        $term = new stdClass();
        if (empty($tid)) {
          GeslibCommon::vprint(t("Updating vocabulary")." (VID ".$vid."): ".$value, 2);
          $term->vid = $vid;
          $term->name = $value;
          taxonomy_term_save($term);
        } else {
          $term->tid = $tid;
        }
        $node->{$field_name}['und'][] = array('tid' => $term->tid);
      }
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
  function update_relationships(&$node, &$object_data) {
    if ($node->nid && ($relations = $object_data['relation'])) {
      $updated = false;
      # Allowed node fields for that object
      $allowed = field_info_instances('node', $this->node_type);
      $linked_elements = array();

      # Loop all relation array
      foreach ( $relations as $rel_name => $rel_values ) {
        $field_name = variable_get('geslib_'.$this->elements_type.'_link_to_'.$rel_name, NULL);
        # If there is any relation defined and it's any of the allowed fields
        if ($field_name && $allowed[$field_name]) {
          GeslibCommon::vprint("Actualizando relaciones con ".$rel_name,2);
          # Loop all related values (it could be multivalued)
          foreach ( $rel_values as $rel_element ) {
            # If related element has node type, we can search it
            if ( $rel_node_type = variable_get('geslib_'.$rel_name.'_node_type', NULL) ) {
              # If author rol is not "author", search another valid field_name
              if ( $rel_name == "author") {
                $rel_field_name = NULL;
                if ($rel_element["function"] == "A") {
                  GeslibCommon::vprint(t("Author rol"),2);
                  $author_field_name = $field_name;
                // Translators
                } elseif ($rel_element["function"] == "T") {
                  GeslibCommon::vprint(t("Translator rol"),2);
                  $author_field_name = variable_get('geslib_'.$this->elements_type.'_link_to_translator', NULL);;
                // Ilustrators
                } else {
                  GeslibCommon::vprint(t("Ilustrator with rol: ") . $rel_element["function"],2);
                  $author_field_name = variable_get('geslib_'.$this->elements_type.'_link_to_ilustrator', NULL);;
                }
                if ($author_field_name && $allowed[$author_field_name]) {
                  $rel_field_name = $author_field_name;
                }
              } else {
                $rel_field_name = $field_name;
              }
              # If field name is not NULL (author rol not related)
              if ( $rel_field_name ) {
                $linked_nid = $this->get_nid_by_gid($rel_element["gid"], $rel_node_type);
                # If related object exists, link it
                if ($linked_nid) {
                  GeslibCommon::vprint("Asociando la referencia a ".$rel_node_type." ('".$rel_field_name."/NID:".$linked_nid."/GID:".$rel_element["gid"].")",2);
                  # If related node field is nodereference, we store only related object nid
                  if (preg_match('#^nodereference#', $allowed[$rel_field_name]['widget_type']) === 1) {
                    $linked_elements[$rel_field_name]['und'][] = array( 'nid' => $linked_nid );
                  # For text fields, store the relation title value
                  } else {
                    $linked = $this->get_node_by_gid($rel_element["gid"], $rel_name, $rel_node_type);
                    $linked_elements[$rel_field_name]['und'][] = array( 'value' => $linked->title );
                    $linked = NULL;
                  }
                } else {
                  GeslibCommon::vprint("ERROR: El nodo ". $rel_node_type ." relacionado (GID:". $rel_element["gid"] .") no pudo encontrarse",0);
                }
              } else {
                GeslibCommon::vprint("No tenemos con que  procesar " . $rel_field_name, 2);
              }
              # If related element could not be found, look it in geslib array
            } else if (preg_match('#^nodereference#', $allowed[$field_name]['widget_type']) !== 1) {
              $referenced_value = $this->elements[$rel_name][$rel_element["gid"]]["title"];
              if ( $referenced_value ) {
                $linked_elements[$field_name]['und'][] = array( 'value' => $referenced_value );
                GeslibCommon::vprint("Asociando el valor ('".$referenced_value."'/GID:".$rel_element["gid"].") como ".$field_name,2);
              } else {
                GeslibCommon::vprint("ERROR: No pudo encontrarse el valor de la referencia en el fichero geslib (".$rel_name."/GID:".$rel_element["gid"].")",0);
              }
            } else {
              GeslibCommon::vprint("ERROR: No se que hacer con el elemento relacionado",0);
            }
          }
          $updated = true;
        }
      }
      # Check that node is ready to save
      if ($updated) {
        # Assign all values to the node
        foreach ($linked_elements as $field_key => $field_value) {
          $node->$field_key = $field_value;
        }
        # Prepare and save node
        if ($node = node_submit($node)) {
          node_save($node);
          GeslibCommon::vprint(t("Node")." '".$node->title."' (NID:".$node->nid."): ".t("relationships updated correctly"),2);
        } else {
          #print_r($node);
          GeslibCommon::vprint(t("Relationships for node")." '".$node->title."' (NID:".$node->nid.") ".t("not updated"), 0);
        }
      } else {
        GeslibCommon::vprint(t("There is no relationships to update"));
      }
    }
  }

  /**
    * Save associated image of the node
    *
    * @param $node
    *     Drupal Node
    * @param object
    *   object properties
    */
  function update_object_image(&$node,&$object_data) {
    $field_name = variable_get('geslib_'.$this->elements_type.'_file_cover_field', NULL);
    if ($node->nid && $field_name) {
      $filename = GeslibCovers::get_cover_file($node,$object_data,$this->elements_type,$field_name);
      # If there is no cover loaded in database, do it
      if ( $filename && !($image = GeslibCovers::drupal_file_load($filename)) ) {
        // Create file object and update files table
        $file = new stdClass();
        $file->filename  = basename($filename);
        # FILEPATH para usar en la tabla files
        #$file->filepath  = $filename;
        # URI se usa en la tabla file_managed (file_save)
        $file->uri       = GeslibCommon::$covers_path . "/". basename($filename);
        $file->filemime  = mime_content_type($filename);
        $file->filesize  = filesize($filename);
        $file->uid       = 1;
        $file->timestamp = time();
        # Files se usaba en D6, tambien en D7?
        #drupal_write_record('files', $file);
        # Esta es la alternativa para D7?
        file_save($file);
        $image = GeslibCovers::drupal_file_load($filename);
      }
      if ( $image && $image[fid] ) {
        $image['alt'] = t("Cover Image") . ": " . $node->title;
        $image['title'] = $node->title;
        $img_field = array('und' => array($image));
        $node->$field_name = $img_field;
        # Check that node is ready to save
        if ($node = node_submit($node)) {
          node_save($node);
          GeslibCommon::vprint(t("Cover image stored"),2);
        } else {
          GeslibCommon::vprint(t("Error storing cover image"),0);
        }
      }
      $file = NULL;
      $image = NULL;
    }
  }

  /**
    * Save associated file of the node
    *
    * @param $node
    *     Drupal Node
    * @param object
    *   object properties
    */
  function update_object_attachment(&$node,&$object_data) {
    $field_name = variable_get('geslib_'.$this->elements_type.'_file_preview_field', NULL);
    if ($node->nid && $field_name) {
      $filename = GeslibCovers::get_attachment_file($node,$object_data,$this->elements_type,$field_name);
      #$filename = $object_data["*preview_url"];
      # If there is no cover loaded in database, do it
      if ( $filename && !($attachment = GeslibCovers::drupal_file_load($filename)) ) {
        // Create file object and update files table
        $file = new stdClass();
        $file->filename  = basename($filename);
        # FILEPATH para usar en la tabla files
        #$file->filepath  = $filename;
        # URI se usa en la tabla file_managed (file_save)
        $file->uri       = GeslibCommon::$attachments_path . "/". basename($filename);
        $file->filemime  = mime_content_type($filename);
        $file->filesize  = filesize($filename);
        $file->uid       = 1;
        $file->timestamp = time();
        # Files se usaba en D6, tambien en D7?
        #drupal_write_record('files', $file);
        # Esta es la alternativa para D7?
        file_save($file);
        $attachment = GeslibCovers::drupal_file_load($filename);
      }
      if ( $attachment && $attachment[fid] ) {
        $attachment['description'] = t("PDF") . ": " . $node->title;
        $attachment_field = array('und' => array($attachment));
        $node->$field_name = $attachment_field;
        # Check that node is ready to save
        if ($node = node_submit($node)) {
          node_save($node);
          GeslibCommon::vprint(t("Attachment stored"),2);
        } else {
          GeslibCommon::vprint(t("Error storing attachment"),0);
        }
      }
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
           ->addMetaData('account', user_load($this->user->uid));
     $result = $query->execute();
     # If there is any result, return nodeid
     if (isset($result['node'])) {
       $nids = array_keys($result['node']);
       $nid = $nids[0];
     } else {
       GeslibCommon::vprint("No results for " . $node_type . " (GID " . $geslib_id . ")", 2);
       $nid = NULL;
     }
     # Nullify variables to force garbage collection
     $query = NULL;
     $result = NULL;
     return $nid;
   }

   /**
   * Get TermID of a vocabulary by term
   *
   * @param vid
   *    vocabulary id
   * @param term
   *    term name
   *
   */
   function get_tid_by_name($vid, $term) {
     $query = new EntityFieldQuery;
     $query->entityCondition('entity_type', 'taxonomy_term')
           ->propertyCondition('name', $term)
           ->propertyCondition('vid', $vid);
     $result = $query->execute();
     # If there is any result, return termid
     if (isset($result['taxonomy_term'])) {
       $tids = array_keys($result['taxonomy_term']);
       $tid = $tids[0];
     } else {
       GeslibCommon::vprint("No results for term " . $term . " in vocabulary ID " . $vid . ")", 2);
       $tid = NULL;
     }
     $query = NULL;
     $result = NULL;
     return $tid;
   }

  /**
   * Get account access to the node
   *
   * @param node
   * @param op ("view","update","create","delete")
   */
  function get_access(&$node, $op) {
    if (!node_access($op, $node, $this->user)) {
      if (!db_query('UPDATE {node} SET uid=%d WHERE nid=%d', $this->user->uid, $node->nid)) {
        throw new Exception('User ' . $this->user->uid . ' not authorized to ' . $op . ' content type ' . $node->type);
      }
      watchdog('geslib-import', "$node->type: Changed ownership of '%node' to user '%user'", array('%node'=>$node->title, '%user'=>$this->user->name));
      GeslibCommon::vprint(t("Changed ownership of"). " ". $node->nid . " (". $node->type ."/". $node->title .") " . t("to user")." ". $this->user->name, 2);
    }
  }

  /**
  * Flush all caches
  */
  function flush_cache() {
    GeslibCommon::vprint("\n---------------------- ".t("Flush all caches"),2);
    drupal_flush_all_caches();
    GeslibCommon::vprint("                       Removed cycles: ".gc_collect_cycles()."\n",2);
  }
}
