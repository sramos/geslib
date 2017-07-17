<?php
/**
 * @author   "Santiago Ramos" <sramos@sitiodistinto.net>
 * @package  Geslib
 */

include_once dirname(__FILE__) . '/lib/Encoding.php';

class GeslibCommon {

  public static $verbose_level=3;

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
  function utf8_encode($string) {
    if ($string) {
      return Encoding::fixUTF8(mb_check_encoding($string, 'UTF-8') ? $string : utf8_encode($string));
    } else {
      return NULL;
    }
  }
}
