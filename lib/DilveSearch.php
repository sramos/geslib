<?php 
/**
 * @author   Santiago Ramos <sramos@semillasl.com>
 * @package  DilveSearch 
 * @version  0.1
  */

class DilveSearch {

  private static $url_host = "www.dilve.es";
  private static $url_path = "/dilve/dilve";
  private static $url_user;
  private static $url_pass;

  static function set_user($user) {
    self::$url_user = $user;
  }
  static function set_pass($pass) {
    self::$url_pass = $pass;
  }
  
  /**
  * Function DilveSearch::search
  *
  * @param string $isbn
  *   ISBN code to search
  * @return hash
  *   hash data of book
  */ 
  static function search($isbn) {
    $book=array();
    $query  = 'http://'.self::$url_host.'/'.self::$url_path.'/getRecordsX.do?user='.self::$url_user.'&password='.self::$url_pass.'&identifier='.$isbn;
    # Get xml in ONIX version 2.1
    $query .= '&metadataformat=ONIX&version=2.1';
    # Get xml in CEGAL version 3
    #$query .= '&metadataformat=CEGAL&version=3&formatdetail=C';
    # By default responses are UTF-8 encoded, but force it
    $query .= '&encoding=UTF-8';
 
    $xml = simplexml_load_file($query);
    #print_r($xml);
    $xml_book = $xml->ONIXMessage->Product[0];
    if ($xml_book) {
      #print_r($xml_book);

      # Get title
      foreach($xml_book->Title as $title) {
        if ($title->TitleType == "01") {
          $book["*title"] = (string)$title->TitleText;
          if ($title->Subtitle) {
            $book["*subtitle"] = (string)$title->Subtitle;
          }
        }
      }
      # Get author
      foreach($xml_book->Contributor as $contributor) {
        if ($contributor->ContributorRole == "A01") {
          $author_name = (string) $contributor->PersonNameInverted;
          $author_description = (string) $contributor->BiographicalNote;
          if ($author_description) {
            $book["*author"][] = array('name' => $author_name, 'description' => $author_description);
          } else {
            $book["*author"][] = array('name' => $author_name);
          }
        }
      }
      # Get measurements
      foreach($xml_book->Measure as $measure) {
        switch ($measure->MeasureTypeCode) {
          case "01":
            $book["*length"] = array('unit' => (string)$measure->MeasureUnitCode, 'value' => (string)$measure->Measurement);
            break;
          case "02":
            $book["*width"] = array('unit' => (string)$measure->MeasureUnitCode, 'value' => (string)$measure->Measurement);
            break;
          case "08":
            $book["*weight"] = array('unit' => (string)$measure->MeasureUnitCode, 'value' => (string)$measure->Measurement);
            break;
        }
      }
      # Get number of pages
      if($xml_book->NumberOfPages) {
        $book["*pages"] = (string)$xml_book->NumberOfPages;
      }
      # Get descriptions
      foreach($xml_book->OtherText as $description) {
        switch ($description->TextTypeCode) {
          case "01":
          case "03":
          case "05":
          case "07":
          case "31":
            $book["*description"] = nl2br( (string) $description->Text );
            break;
          case "09":
            $book["*promoting_description"] = nl2br( (string) $description->Text );
            break;
          case "12":
            $book["*short_description"] = nl2br( (string) $description->Text );
            break;
          case "13":
            if ( count($book['*author']) == 1 ) {
              $book["*author"][0]["description"] = nl2br( (string) $description->Text );
            }
            break;
          case "23":
            $book["*preview_url"] = self::get_file_url((string) $description->TextLink, $isbn);
            #print "\n---> Recogido fichero de preview: " . $book["*preview_url"] ." --- ";
            #print_r($description);
            break;
          default:
            #print "\n-----------------------> Tipo de texto no definido (".$description->TextTypeCode.") para el libro con ISBN ".$isbn."\n\n";
        }
      }
      # Get cover URL
      foreach ($xml_book->MediaFile as $media) {
        switch ($media->MediaFileTypeCode) {
          # Covers
          case "03":
          case "04":
          case "05":
          case "06":
            # Its better dilve uris
            if ( !$book["*cover_url"] || $media->MediaFileLinkTypeCode == "06" ) {
              $book["*cover_url"] = self::get_file_url((string) $media->MediaFileLink, $isbn);
            }
            break;
          # Cover miniature
          case "07":
            break;
          # Author image
          case "08":
            $book["*image_author_url"] = self::get_file_url((string) $media->MediaFileLink, $isbn);
            #print "\n---> Recogido imagen del autor: " . $book["*image_author_url"];
            #print "\n---> Formato: " . $media->MediaFileFormatCode;
            #print "\n---> Tipo de Enlace: " . $media->MediaFileLinkTypeCode;
            break;
          # Publisher logo
          case "17":
            $book["*image_publisher_url"] = self::get_file_url((string) $media->MediaFileLink, $isbn);
            #print "\n---> Recogido logo de editorial: " . $book["*image_publisher_url"];
            #print "\n---> Formato: " . $media->MediaFileFormatCode;
            #print "\n---> Tipo de Enlace: " . $media->MediaFileLinkTypeCode;
            break;
          # Preview book
          case "51";
            #$book["*preview_media_url"] = self::get_file_url((string) $media->MediaFileLink, $isbn);
            #print "\n---> Recogido fichero de preview: " . $book["*preview_media_url"];
            #print "\n---> Formato: " . $media->MediaFileFormatCode;
            #print "\n---> Tipo de Enlace: " . $media->MediaFileLinkTypeCode;
            #break;
          default:
            #print_r ($media);
            #print "\n-----------------------> Tipo de medio no definido (".$media->MediaFileTypeCode.") para el libro con ISBN ".$isbn."\n\n";
        }
      }
    }

    return $book;
  }

  /**
  * Function DilveSearch::get_file_url
  *
  * @param string $filename
  *   local or remote filename
  * @param string $isbn
  *   ISBN code to search
  * @return string
  *   Full URL of requested resource 
  */
  private static function get_file_url($filename, $isbn) {
    # If URL is a DILVE reference, complete full request
    if (!strncmp($filename, 'http://', 7) || !strncmp($filename, 'https://', 8)) {
      $url = $filename;
    } else {
      $url  = 'http://'.self::$url_host.'/'.self::$url_path.'/getResourceX.do?user='.self::$url_user.'&password='.self::$url_pass;
      $url .= '&identifier='.$isbn.'&resource='.urlencode($filename);
    }
    return $url;
  }
}
