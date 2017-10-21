<?php

/**
 * @file
 * @author   "Santiago Ramos" <sramos@sitiodistinto.net>
 * @package  Geslib
 */

include_once dirname(__FILE__) . '/GeslibCommon.php';

class GeslibCovers {

  public static $user_agent = "Mozilla/5.0 (X11; Ubuntu; Linux i686; rv:15.0) Gecko/20100101 Firefox/15.0.1";

  /**
  * Function GeslibCovers::download_file
  *
  * @param string url
  *   URI of the image to be downloaded
  * @param string path
  *   path where image will be stored
  * @return string
  *   filename of downloaded image
  */
  static function download_file($url, $path, $isbn) {
    $saved_file = NULL;
    $context = stream_context_create(array('http' => array('header' => 'Host: '.parse_url($url, PHP_URL_HOST), 'user_agent' => self::$user_agent)));
    $orig_file = file_get_contents($url,0,$context);
    if (!($orig_file === false)) {
      # Gets file content type
      foreach ($http_response_header as $header) {
        preg_match("/^Content-Type: .+\/([^;]+)/", $header, $matches);
        if ( $matches ) {
          $ext = $matches[1];
        }
      }
      # If no extension was returned in content type headers, get it from url
      if (! $ext) {
        #print "----------> No existe content type para el elemento!!!\n";
        $ext = pathinfo($url, PATHINFO_EXTENSION);
      }
      $filename = $path . '/' . $isbn . "." . strtolower($ext);
      # Write file to disk
      file_put_contents($filename, $orig_file);
      if ( file_exists($filename) ) {
        $saved_file = $filename;
      }
    }
    return $saved_file;
  }

  /**
    * Save and return associated image of the node
    *
    * @param $node
    *     Drupal Node
    * @param object
    *   object properties
    */
  static function get_cover_file(&$node,&$object_data,$element_type) {
    $image_file = NULL;
    $image_field_name = variable_get('geslib_'.$element_type.'_file_cover_field', NULL);
    if ($node->nid && $image_field_name) {
      $image_field = $node->$image_field_name;
      $default_image = variable_get('geslib_'.$element_type.'_default_image', NULL);
      // Hay que revisar la comparacion entre la configuracion y el resultado de uri
      if (empty($image_field['und'][0]) ||
          drupal_realpath($image_field['und'][0]['uri']) == $default_image) {
        $cover_url = $object_data["*cover_url"];
        $uploaded_cover = GeslibCovers::get_uploaded_cover_file($node);
        # If not book cover exists try to download it
        if (!$uploaded_cover && $cover_url) {
          GeslibCommon::vprint(t("Downloading remote book cover") . ": " . $cover_url,2);
          $image_file = GeslibCovers::download_file($cover_url, GeslibCommon::$covers_path, $node->model);
          # If content type is not an image, delete it
          $ext = pathinfo($image_file, PATHINFO_EXTENSION);
          if ($ext != "jpeg" && $ext != "png" && $ext != "jpg" && $ext != "gif" && $ext != "tiff") {
            GeslibCommon::vprint(t("Remote book cover not valid").": ".$image_file,2);
            unlink($image_file);
            $image_file = NULL;
          }
        }
        # Use default one
        if (!$image_file && !$image_field['und'][0]) {
          $image_file = $default_image_file;
          if ($image_file) {
            GeslibCommon::vprint(t("Using default cover"));
          }
        }
      }
    } elseif (empty($image_field_name)){
      GeslibCommon::vprint(t("No hay atributo definido para imagenes"),3);
    }
    # Return cover path
    return $image_file;
  }

  /**
    * Save and return associated image of the node
    *
    * @param $node
    *     Drupal Node
    * @param object
    *   object properties
    */
  static function get_attachment_file(&$node,&$object_data,$element_type) {
    $attachment_file = NULL;
    $attachment_field_name = variable_get('geslib_'.$element_type.'_file_preview_field', NULL);
    $attachment_field = $node->$attachment_field_name;
    if ($node->nid && !$attachment_field['und'][0] ) {
      $preview_url = $object_data["*preview_url"];
      if ($preview_url) {
        GeslibCommon::vprint(t("Downloading remote book attachment") . ": " . $preview_url,2);
        $attachment_file = GeslibCovers::download_file($preview_url, GeslibCommon::$attachments_path, $node->model);
        # If content type is not an image, delete it
        $ext = pathinfo($attachment_file, PATHINFO_EXTENSION);
        if ($ext != "pdf") {
          GeslibCommon::vprint(t("Remote book attachment not valid").": ".$attachment_file,2);
          unlink($attachment_file);
          $attachment_file = NULL;
        }
      }
    }
    # Return cover path
    return $attachment_file;
  }

  /**
    * Return uploaded image of the node
    *
    * @param $node
    *     Drupal Node
    * @param object
    *   object properties
    */
    static function get_uploaded_cover_file(&$node) {
      return NULL;
    }

    static function drupal_file_load($filename) {
      $fid = db_select('file_managed', 'f')->condition('filename', basename($filename))
                                           ->fields('f', array('fid'))
                                           ->execute()
                                           ->fetchAll();
      if ($fid) {
        return (array)file_load($fid[0]->fid);
      } else {
        return NULL;
      }
    }
}
