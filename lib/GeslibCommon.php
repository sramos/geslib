<?php
/**
 * @author   "Santiago Ramos" <sramos@sitiodistinto.net>
 * @package  Geslib
 */

include_once dirname(__FILE__) . '/Encoding.php';

class GeslibCommon {

  public static $verbose_level=3;
  private static $user_agent = "Mozilla/5.0 (X11; Ubuntu; Linux i686; rv:15.0) Gecko/20100101 Firefox/15.0.1";

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
    if ($level < GeslibCommon::$verbose_level) {
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
  function utf8_encode($string) {
    if ($string) {
      return Encoding::fixUTF8(mb_check_encoding($string, 'UTF-8') ? $string : utf8_encode($string));
    } else {
      return NULL;
    }
  }

  /**
  * Function GeslibCommon::download_file
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
        preg_match("/^Content-Type: .+\/(.+)/", $header, $matches);
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

}
