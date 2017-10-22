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
      $filename = $path . $isbn . "." . strtolower($ext);
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
    * @param $object
    *   object properties
    * @param $element_type
    *   geslib element type
    * @param $image_field_name
    *   node field to store images
    */
  static function get_cover_file(&$node,&$object_data,$element_type,$image_field_name) {
    $image_file = NULL;
    $image_field = $node->$image_field_name;
    $default_image = variable_get('geslib_'.$element_type.'_default_image', NULL);
    // Hay que revisar la comparacion entre la configuracion y el resultado de uri
    if (empty($image_field['und'][0]) ||
        drupal_realpath($image_field['und'][0]['uri']) == $default_image) {
      $ean = $node->model;
      $image_file = GeslibCovers::get_uploaded_cover_file($ean);
      # If not book cover exists try to download it
      $cover_url = $object_data["*cover_url"];
      if (!$image_file && $cover_url) {
        GeslibCommon::vprint(t("Downloading remote book cover") . ": " . $cover_url,2);
        $image_file = GeslibCovers::download_file($cover_url, GeslibCommon::$covers_path, $ean);
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
    * @param $element_type
    *   geslib element type
    * @param $attachment_field_name
    *   node field to store images
    */
  static function get_attachment_file(&$node,&$object_data,$element_type,$attachment_field_name) {
    $attachment_file = NULL;
    $attachment_field = $node->$attachment_field_name;
    if (!$attachment_field['und'][0] ) {
      $preview_url = $object_data["*preview_url"];
      if ($preview_url) {
        GeslibCommon::vprint(t("Downloading remote book attachment") . ": " . $preview_url,2);
        $attachment_file = GeslibCovers::download_file($preview_url, GeslibCommon::$attachments_path, $node->model);
        # If content type is not an pdf, delete it
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
    * @param $ean
    *     EAN book
    */
    static function get_uploaded_cover_file($ean) {
      $found = FALSE;
      $covers_path = variable_get('geslib_upload_cover_path', NULL);
      $dest_covers_path = GeslibCommon::$covers_path;
      # Try with known image extensions
      if ($ean && $covers_path) {
        $remove_originals = variable_get('geslib_delete_original_covers', NULL);
        $short_ean = substr($ean, 0, 12);
        foreach ( array("gif", "GIF", "jpg", "JPG", "jpeg", "JPEG", "png", "PNG")  as $extension ) {
          $subdir = substr($ean, 0, 6);
          // Each of possible locations for cover images
          $locations = array( $covers_path."/".$ean.".".$extension,
                              $covers_path."/".substr($ean, 0 , 12).".".$extension,
                              $covers_path."/".$subdir."/".$ean.".".$extension,
                              $covers_path."/".$subdir."/".substr($ean, 0 , 12).".".$extension );
          foreach ( $locations as $filename ) {
            if ( !$found && realpath( $filename ) ) {
              // File exists, copy to final destination!!!
              $cover_file = $dest_covers_path.$ean.".".strtolower($extension);
              if ( copy( $filename, $cover_file ) ) {
                $found = TRUE;
                # Delete original cover
                if ( $remove_originals ) {
                  unlink($filename);
                }
                break;
              } else {
                GeslibCommon::vprint("ERROR: No se pudo copiar '".$filename."' sobre '".$cover_file."'",2);
              }
            }
          }
        }
      }
      if ($found) {
        GeslibCommon::vprint(t("Using uploaded book cover: ").$cover_file,3);
      } else {
        $cover_file = null;
      }
      return $cover_file;
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
