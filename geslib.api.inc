<?php
/**
 * @author   "Santiago Ramos" <sramos@sitiodistinto.net>
 * @package  Geslib
 */

include_once dirname(__FILE__) . '/lib/GeslibCommon.php';
include_once dirname(__FILE__) . '/lib/GeslibReader.php';
include_once dirname(__FILE__) . '/lib/GeslibWriter.php';

class geslib_api {
  private $geslib_files;
  private $geslib_filename;
  private $elements;

  /**
  * @param $geslib_filename
  *     Geslib export file
  */
  function __construct($geslib_filepath, $verbose_level) {
    global $user;
    $this->user = $user;
    GeslibCommon::$verbose_level = $verbose_level;
    $this->default_nom_category = GeslibCommon::utf8_encode(variable_get('geslib_category_default_name', NULL));
    $this->default_nom_collection = GeslibCommon::utf8_encode(variable_get('geslib_collection_default_name', NULL));
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
    # Comprueba que los "geslib_files" leidos en la inicializacion no esten ya procesados
    foreach($this->geslib_files as $file) {
      $query = "SELECT id FROM {geslib_log} WHERE component = 'geslib_file' AND imported_file = :file AND status IN ('ok', 'working')";
      $result = db_query($query, array(':file' => basename($file)));
      if ($result->rowCount() == 0) {
        $files[] = $file;
      } else {
        GeslibCommon::vprint(t("Excluded previously imported file") . " " . $file,1);
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
      $this->geslib_filename = $filename;
      $this->elements = array();
      # Check if there is a previously serialized version of file
      $processed_filename = $filename . ".serialized";
      if ( file_exists($processed_filename) ) {
        GeslibCommon::vprint(t("Reading previously serialized file"),1);
        $this->elements = unserialize(file_get_contents($processed_filename));
        #$this->elements = json_decode(file_get_contents($processed_filename), true);
      } else {
        $reader = new GeslibReader($filename, $this->default_nom_collection, $this->default_nom_category);
        $this->elements = &$reader->getElements();

        # If user wants to search book data
        if (variable_get('geslib_book_download_info', NULL)) {
          $this->preprocess_book_data();
        }

        # Write data to disk
        GeslibCommon::vprint(t("Saving serialized file"),1);
        file_put_contents($processed_filename,serialize($this->elements));
        #file_put_contents($processed_filename,json_encode($this->elements));
      }
    }
  }

  /**
  * Import all elements
  */
  function import_elements() {
    # Write action in log table
    $log_import = array('start_date' => time(),
                        'component' => 'geslib_file',
                        'imported_file' => basename($this->geslib_filename),
                        'uid' => $this->user->uid, 'status' => 'working');
    drupal_write_record('geslib_log', $log_import);
    # For each element type process imported information if not null and drupal_node_type exists
    $items = array('category','publisher','music_publisher',
                   'author','collection','publisher_reference',
                   'library_reference','index','book');
    foreach ($items as $item) {
      $writer = new GeslibWriter($item, $this->elements[$item], basename($this->geslib_filename), $this->user);
      $writer->save_items();
    }
    # Write finishing data into import table
    $log_import['end_date'] = time();
    $log_import['status'] = 'ok';
    drupal_write_record('geslib_log', $log_import, 'id');
    # Delete geslib file
    if ( variable_get('geslib_delete_imported_files', NULL) ) {
      GeslibCommon::vprint("\n---------------------- ".t("Clear imported file")."\n",2);
      #unlink($this->geslib_filename);
      #unlink($this->geslib_filename . ".json");
      #unlink($this->geslib_filename . ".serialized");
    }
  }

  /**
  * Import pending covers
  */
  function import_covers() {
    $writer = new GeslibWriter('covers', $this->elements['book'], basename($this->geslib_filename), $this->user);
    $writer->save_elements();
  }

  /**
  * Get data from internet
  *
  * @param &object
  *   reference to a book element
  */
  function preprocess_book_data() {
    GeslibCommon::vprint(t("Searching external book data"),1);

    $geslib_book_type = variable_get('geslib_book_geslib_type', NULL);
    # Loop all books
    $elements = $this->elements["book"];
    foreach ($elements as $object_id => $object) {
      # Only for books that will not be deleted
      if ( $object["type"] == $geslib_book_type && $object["action"] != "B") {
        $this->get_internet_book_data($object_id);
      }
    }
    $elements = NULL;
    GeslibCommon::vprint(" ",1);
  }

  /**
  * Get book data from internet
  *
  * @param $object_id
  *   book element id
  */
  function get_internet_book_data($object_id) {
    $inet_search_type = variable_get('geslib_book_download_info', NULL);
    $ean = $this->elements["book"][$object_id]["attribute"]["ean"];
    $needed_author = (variable_get('geslib_book_create_fake_author', NULL) == 2 || sizeof($this->elements["book"][$object_id]["*author"]) == 0) &&
                     sizeof($this->elements["book"][$object_id]["relation"]["author"]) == 0;
    $needed_description = variable_get('geslib_book_body_from', NULL) &&
                          sizeof($this->elements["book"][$object_id]["relation"][variable_get('geslib_book_body_from', NULL)]) == 0;

    # Only search book if no geslib data available
    if ( $ean && ( $inet_search_type || ( $inet_search_type == "2" && ( $needed_description || $needed_author ) ) ) ) {
      $inet_data = array();
      GeslibCommon::vprint(t("Searching")." '".$this->elements["book"][$object_id]["title"]."' (EAN: ".$ean.")",2);

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
      if ( $inet_data["*title"] && $this->elements["book"][$object_id]["title"] == NULL ) {
        $this->elements["book"][$object_id]["title"] = $inet_data["*title"];
        GeslibCommon::vprint(t("Loaded title"));
      }
      # Import subtitle
      if ( $inet_data["*subtitle"] && $this->elements["book"][$object_id]["attribute"]["subtitle"] == NULL ) {
        $this->elements["book"][$object_id]["attribute"]["subtitle"] = $inet_data["*subtitle"];
        GeslibCommon::vprint(t("Loaded subtitle"));
      }
      # Import description
      if ( $inet_data["*description"] && $needed_description ) {
        if ( variable_get('geslib_book_body_from', NULL) == "external_description" ) {
          $this->elements["book"][$object_id]["body"] = $inet_data["*description"];
        } else {
          $this->elements[variable_get('geslib_book_body_from', NULL)][$object_id . "_1"]["title"] = $inet_data["*description"];
          $this->elements[variable_get('geslib_book_body_from', NULL)][$object_id . "_1"]["body"] = $inet_data["*description"];
          $this->elements[variable_get('geslib_book_body_from', NULL)][$object_id . "_1"]["*title_from_related_book"] = $object_id;
          $this->elements["book"][$object_id]["relation"][variable_get('geslib_book_body_from', NULL)][] = array("gid" => $object_id . "_1");
        }
        GeslibCommon::vprint(t("Loaded book description"));
        #print_r($this->elements[variable_get('geslib_book_body_from', NULL)][$object_id . "_1"]);
      }
      # Import measurements
      if ( $inet_data["*weight"] && $this->elements["book"][$object_id]["uc_product"]["weight"] == "" ) {
        $this->elements["book"][$object_id]["uc_product"]["weight"] = $inet_data["*weight"]["value"];
        $this->elements["book"][$object_id]["*weight_unit"] = $inet_data["*weight"]["unit"];
      }
      if ( $inet_data["*width"] && $this->elements["book"][$object_id]["uc_product"]["width"] == "" ) {
        $this->elements["book"][$object_id]["uc_product"]["width"] = $inet_data["*width"]["value"];
        $this->elements["book"][$object_id]["*width_unit"] = $inet_data["*width"]["unit"];
      }
      if ( $inet_data["*length"] && $this->elements["book"][$object_id]["uc_product"]["length"] == "" ) {
        $this->elements["book"][$object_id]["uc_product"]["length"] = $inet_data["*length"]["value"];
        $this->elements["book"][$object_id]["*length_unit"] = $inet_data["*length"]["unit"];
      }
      if ( $inet_data["*pages"] && $this->elements["book"][$object_id]["attribute"]["pages"] == "" ) {
        $this->elements["book"][$object_id]["attribute"]["pages"] = $inet_data["*pages"];
      }
      if ( $inet_data["*format"] && $this->elements["book"][$object_id]["attribute"]["format"] == "" ) {
        $this->elements["book"][$object_id]["attribute"]["format"] = $inet_data["*format"];
      }
      if ( $inet_data["*edition_date"] && $this->elements["book"][$object_id]["attribute"]["edition_date"] == "" ) {
        $this->elements["book"][$object_id]["attribute"]["edition_date"] = $inet_data["*edition_date"];
      }

      # Import cover
      if ($inet_data["*cover_url"] && $inet_search_type != "2") {
        $this->elements["book"][$object_id]["*cover_url"] = $inet_data["*cover_url"];
        GeslibCommon::vprint(t("Loaded book cover URL"));
      }

      # Import preview file
      if ($inet_data["*preview_url"]) {
        $this->elements["book"][$object_id]["*preview_url"] = $inet_data["*preview_url"];
        GeslibCommon::vprint(t("Loaded preview URL"));
      }

      # Import author description
      if (count($inet_data["*author"]) == 1 && count($this->elements["book"][$object_id]["*author"]) == 1 && $inet_data["*author"][0]["description"]) {
          $this->elements["book"][$object_id]["*author"][0]["description"] = $tmp_data["*author"][0]["description"];
      }
      # Import author name
      if ($inet_data["*author"] && (variable_get('geslib_book_create_fake_author', NULL) == 2 || sizeof($this->elements["book"][$object_id]["*author"]) == 0)) {
        $this->elements["book"][$object_id]["*author"] = $inet_data["*author"];
        GeslibCommon::vprint(t("Loaded author"));
      }

    }
  }
}
