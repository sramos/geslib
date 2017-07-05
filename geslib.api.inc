<?php

include_once dirname(__FILE__) . '/lib/Encoding.php';
include_once dirname(__FILE__) . '/lib/GeslibReader.php';
include_once dirname(__FILE__) . '/lib/InetBookSearch.php';
include_once dirname(__FILE__) . '/lib/DilveSearch.php';


class geslib_api {

  private $geslib_files;
  private $geslib_filename;
  private $elements;

  /**
  * @param $geslib_filename
  *     Geslib export file
  */
  function __construct($geslib_filepath, $verbose_level) {
    $this->user = user_load(array('uid' => 1));
    $this->default_nom_category = $this->geslib_utf8_encode(variable_get('geslib_category_default_name', NULL));
    $this->default_nom_collection = $this->geslib_utf8_encode(variable_get('geslib_collection_default_name', NULL));
    $this->verbose_level = $verbose_level;
    $this->geslib_files = $this->select_geslib_files($geslib_filepath);
    # Check if covers upload path is writable
    $covers_path = drupal_realpath('public://book_covers');
    if ( !file_prepare_directory($covers_path, FILE_CREATE_DIRECTORY) ) {
      throw new Exception('No rights to write in book covers path ('. $covers_path . ')' );
    }
    # Initialize Dilve access
    if ( variable_get('geslib_book_download_info', NULL) == "5" ) {
      DilveSearch::set_user(variable_get('geslib_dilve_user', NULL));
      DilveSearch::set_pass(variable_get('geslib_dilve_pass', NULL));
    }
  }

  /**
  * @param $path
  *   Path of geslib files or file path
  *
  * returns (array)
  *   geslib files to be readed
  */
  function select_geslib_files($path) {
    $geslib_files = array();
    if (is_dir($path)) {
      $dir_array = array();
      $handle = opendir($path);
      while (false !== ($file = readdir($handle))) {
        # Get only INTER(ddd) files
        if (preg_match('/^INTER(\d)+$/', $file)) {
            $geslib_files[] = $path."/".$file;
        }
      }
      closedir($handle);
    } else {
      $geslib_files[] = $path;
    }

    return $geslib_files;
  }

  /**
  * Get files to import
  */
  function get_pending_files() {
    $files = array();
    foreach($this->geslib_files as $file) {
      $query = 'SELECT id FROM {geslib_log} WHERE component = "geslib_file" AND imported_file = "%s" AND (status = "ok" OR status ="working")';
      $result = db_query($query, basename($file));
      if (!$result || !$result->fetchObject()) {
        $files[] = $file;
      } else {
        $this->vprint(t("Excluded previously imported file") . " " . $file,1);
      }
    }
    sort($files);
    return $files;
  }

  /**
  * Read geslib_filename line by line
  *
  * @param filename
  *   file to be imported
  */
  function read_file($filename) {
    if (empty($filename) || !file_exists ($filename)) {
      throw new Exception(t('Geslib file not valid.'));
    } else {
      # Write reading file into log
      $log_import = array('start_date' => date('Y-m-d H:i:s'),
                          'component' => 'geslib_file',
                          'imported_file' => basename($filename),
                          'uid' => $this->user->uid, 'status' => 'working');
      drupal_write_record('geslib_log', $log_import);
      $this->geslib_filename = $filename;
      $this->elements = array();

      $reader = new GeslibReader($filename, $this->default_nom_collection, $this->default_nom_category);
      $this->elements = &$reader->getElements();

      # If user wants to search book data
      if (variable_get('geslib_book_download_info', NULL)) {
        $this->preprocess_book_data();
      }

      # Write data to database
      $this->vprint(t("Writing data to database"),1);
      $processed_lines = 0;
      #file_put_contents($filename . ".serialized",serialize($this->elements));
      # For each element type process imported information if not null and drupal_node_type exists
      foreach ($this->elements as $element_type => $items) {
        # Store all items of element_type
        $processed_lines += count($items);
        foreach ($items as $item_id => $item_data) {
          $record = array();
          $record['geslib_id'] = $item_id;
          $record['type'] = $element_type;
          $record['inter_file'] = $filename;
          $record['content'] = serialize($item_data);

          try {
            drupal_write_record('geslib_lines', $record);
          } catch (Exception $e) {
            watchdog('geslib', "Geslib id: @id Type: @type Message: @message",
                     array('@id' => $record['geslib_id'], '@type' => $record['type'], '@message' => $e->getMessage()) );
          }
        }
      }
      # Write file readed into log
      $this->vprint(t("Writing log file"),1);
      $log_import['end_date'] = date('Y-m-d H:i:s');
      $log_import['items'] = $processed_lines;
      $log_import['status'] = 'ok';
      drupal_write_record('geslib_log', $log_import, 'id');
    }
  }

  /**
  * Get data from internet
  *
  * @param &object
  *   reference to a book element
  */
  function preprocess_book_data() {
    $this->vprint(t("Searching external book data"),1);

    $geslib_book_type = variable_get('geslib_book_geslib_type', NULL);
    # Loop all products
    $elements = $this->elements["product"];
    foreach ($elements as $object_id => $object) {
      # Only for books that will not be deleted
      if ( $object["type"] == $geslib_book_type && $object["action"] != "B") {
        $this->get_internet_book_data($object_id);
      }
    }
    $elements = NULL;
    $this->vprint(" ",1);
  }

  /**
  * Get book data from internet
  *
  * @param $object_id
  *   book element id
  */
  function get_internet_book_data($object_id) {
    $inet_search_type = variable_get('geslib_book_download_info', NULL);
    $ean = $this->elements["product"][$object_id]["attribute"]["ean"];
    $needed_author = (variable_get('geslib_book_create_fake_author', NULL) == 2 || sizeof($this->elements["product"][$object_id]["*author"]) == 0) &&
                     sizeof($this->elements["product"][$object_id]["relation"]["author"]) == 0;
    $needed_description = variable_get('geslib_book_body_from', NULL) &&
                          sizeof($this->elements["product"][$object_id]["relation"][variable_get('geslib_book_body_from', NULL)]) == 0;

    # Only search book if no geslib data available
    if ( $ean && ( $inet_search_type || ( $inet_search_type == "2" && ( $needed_description || $needed_author ) ) ) ) {
      $inet_data = array();
      $this->vprint(t("Searching")." '".$this->elements["product"][$object_id]["title"]."' (EAN: ".$ean.")",2);

      # Search for EAN code by priority
      if ($inet_search_type == "5") {
        $inet_data = DilveSearch::search($ean);
      }
      if ($inet_search_type == "1" || $inet_search_type == "2" || $inet_data["*cover_url"] == NULL ||
           ( $inet_data["*author"] == NULL && $needed_author ) ||
           ( $inet_data["*description"] == NULL && $needed_description ) ) {
        $tmp_data = InetBookSearch::search_ttl($ean);
        if ($inet_search_type == "5" && $tmp_data["*cover_url"]) {
          unset($tmp_data["*cover_url"]);
        }
        # Mixes both downloaded data
        if (count($inet_data["*author"]) == 1 && count($tmp_data["*author"]) == 1 && !$inet_data["*author"][0]["description"] && $tmp_data["*author"][0]["description"]) {
          $inet_data["*author"][0]["description"] = $tmp_data["*author"][0]["description"];
        }
        foreach ($tmp_data as $key => $value) {
          if ( ! $inet_data[$key] ) {
            $inet_data[$key] = $value;
          }
        }
      }
      if ( ($inet_search_type != "2" && $inet_data["*cover_url"] == NULL) ||
           ( $inet_data["*description"] == NULL && $needed_description ) ) {
        $tmp_data = InetBookSearch::search_google($ean);
        foreach ($tmp_data as $key => $value) {
          if ( ! $inet_data[$key] ) {
            $inet_data[$key] = $value;
          }
        }
      }

      #print_r($inet_data);

      # Import title
      if ( $inet_data["*title"] && $this->elements["product"][$object_id]["title"] == NULL ) {
        $this->elements["product"][$object_id]["title"] = $inet_data["*title"];
        $this->vprint(t("Loaded title"));
      }
      # Import subtitle
      if ( $inet_data["*subtitle"] && $this->elements["product"][$object_id]["attribute"]["subtitle"] == NULL ) {
        $this->elements["product"][$object_id]["attribute"]["subtitle"] = $inet_data["*subtitle"];
        $this->vprint(t("Loaded subtitle"));
      }
      # Import description
      if ( $inet_data["*description"] && $needed_description ) {
        if ( variable_get('geslib_book_body_from', NULL) == "external_description" ) {
          $this->elements["product"][$object_id]["body"] = $inet_data["*description"];
        } else {
          $this->elements[variable_get('geslib_book_body_from', NULL)][$object_id . "_1"]["title"] = $inet_data["*description"];
          $this->elements[variable_get('geslib_book_body_from', NULL)][$object_id . "_1"]["body"] = $inet_data["*description"];
          $this->elements[variable_get('geslib_book_body_from', NULL)][$object_id . "_1"]["*title_from_related_book"] = $object_id;
          $this->elements["product"][$object_id]["relation"][variable_get('geslib_book_body_from', NULL)][] = array("gid" => $object_id . "_1");
        }
        $this->vprint(t("Loaded book description"));
        #print_r($this->elements[variable_get('geslib_book_body_from', NULL)][$object_id . "_1"]);
      }
      # Import measurements
      if ( $inet_data["*weight"] && $this->elements["product"][$object_id]["uc_product"]["weight"] == "" ) {
        $this->elements["product"][$object_id]["uc_product"]["weight"] = $inet_data["*weight"]["value"];
        $this->elements["product"][$object_id]["*weight_unit"] = $inet_data["*weight"]["unit"];
      }
      if ( $inet_data["*width"] && $this->elements["product"][$object_id]["uc_product"]["width"] == "" ) {
        $this->elements["product"][$object_id]["uc_product"]["width"] = $inet_data["*width"]["value"];
        $this->elements["product"][$object_id]["*width_unit"] = $inet_data["*width"]["unit"];
      }
      if ( $inet_data["*length"] && $this->elements["product"][$object_id]["uc_product"]["length"] == "" ) {
        $this->elements["product"][$object_id]["uc_product"]["length"] = $inet_data["*length"]["value"];
        $this->elements["product"][$object_id]["*length_unit"] = $inet_data["*length"]["unit"];
      }
      if ( $inet_data["*pages"] && $this->elements["product"][$object_id]["attribute"]["pages"] == "" ) {
        $this->elements["product"][$object_id]["attribute"]["pages"] = $inet_data["*pages"];
      }
      if ( $inet_data["*format"] && $this->elements["product"][$object_id]["attribute"]["format"] == "" ) {
        $this->elements["product"][$object_id]["attribute"]["format"] = $inet_data["*format"];
      }
      if ( $inet_data["*edition_date"] && $this->elements["product"][$object_id]["attribute"]["edition_date"] == "" ) {
        $this->elements["product"][$object_id]["attribute"]["edition_date"] = $inet_data["*edition_date"];
      }

      # Import cover
      if ($inet_data["*cover_url"] && $inet_search_type != "2") {
        $this->elements["product"][$object_id]["*cover_url"] = $inet_data["*cover_url"];
        $this->vprint(t("Loaded book cover URL"));
      }

      # Import preview file
      if ($inet_data["*preview_url"]) {
        $this->elements["product"][$object_id]["*preview_url"] = $inet_data["*preview_url"];
        $this->vprint(t("Loaded preview URL"));
      }

      # Import author description
      if (count($inet_data["*author"]) == 1 && count($this->elements["product"][$object_id]["*author"]) == 1 && $inet_data["*author"][0]["description"]) {
          $this->elements["product"][$object_id]["*author"][0]["description"] = $tmp_data["*author"][0]["description"];
      }
      # Import author name
      if ($inet_data["*author"] && (variable_get('geslib_book_create_fake_author', NULL) == 2 || sizeof($this->elements["product"][$object_id]["*author"]) == 0)) {
        $this->elements["product"][$object_id]["*author"] = $inet_data["*author"];
        $this->vprint(t("Loaded author"));
      }

    }
  }

  /**
  * Print output messages
  *
  * @param $string
  *     Output message
  * @param $verbose=3
  *     Verbose level for message
  */
  function vprint($string, $level = 3, $type = NULL) {
    # For errors, write it in logs
    if ($level == 0) {
      watchdog('geslib-import', $string, NULL, WATCHDOG_ERROR);
    }
    # Output message if verbose level is greater
    if ($level < $this->verbose_level) {
      # Output formating
      $pre = array("\n*** ", "   * ", "       ", "          ");
      drush_print($pre[$level].$string);
    }
  }

  /**
  * Convert and Fix UTF8 strings
  *
  * @param $string
  *     String to be fixed
  *
  * Returns
  *     $string
  */
  function geslib_utf8_encode($string) {
    if ($string) {
      return Encoding::fixUTF8(mb_check_encoding($string, 'UTF-8') ? $string : utf8_encode($string));
    } else {
      return NULL;
    }
  }
}
