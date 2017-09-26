<?php
/**
 * @author   Santiago Ramos <sramos@sitiodistinto.net>
 * @package  InetBookSearch
 * @version  0.2
  */

class InetBookSearch {

  /**
  * Function InetBookSearch::search_google
  *
  * @param string $isbn
  *   ISBN code to search
  * @return hash
  *   hash data of book
  */
  static function search_google($isbn) {
    libxml_use_internal_errors(true);
    $book = array();
    $url = 'https://books.google.com/books?q=isbn%3A'.$isbn;
    $book_data = drupal_http_request($url);
    #dpm($book_data);
    #print "\n------------------> ".$book_data."\n";
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->loadHTML($book_data->data);
    $xpath = new DOMXpath($dom);
    $books = $xpath->query("//h2[@class='resbdy']//a");

    if ($books->length > 0) {
      $book_path = $books->item(0)->getAttribute('href');
      #print "\n\nTENEMOS EL ENLACE AL LIBRO!!!!! " . $book_path . "\n\n";
      $book_data =  file_get_contents($book_path."&redir_esc=y");
      #print "\n------------------> ".$book_data."\n";
      $dom = new DOMDocument();
      $dom->preserveWhiteSpace = false;
      $dom->loadHTML($book_data);
      $xpath = new DOMXpath($dom);

      # Get book description
      $element = $xpath->query("//div[@id='synopsistext']//p");
      if ( $element->length > 0 ) {
        $tmp_value = $element->item(0)->nodeValue;
        if ($tmp_value != "Unknown") {
          $book['*description'] = $tmp_value;
        }
      }

      # Get book cover
      $element = $xpath->query("//div[@class='bookcover']//img");
      if ( $element->length > 0 ) {
        $tmp_value = $element->item(0)->getAttribute('src');
        if ($tmp_value != "/googlebooks/images/no_cover_thumb.gif") {
          $book['*cover_url'] = $tmp_value;
        }
      }
    }

    return $book;
  }

  /**
  * Function InetBookSearch::search_cdl_cover
  *
  * @param string $isbn
  *   ISBN code to search
  * @return url
  *   url string for book cover
  */
  static function search_cdl_cover($isbn) {
    $url = sprintf("https://imagessl.casadellibro.com/a/l/t0/%s/%s.jpg", substr($isbn, 11, 2), $isbn);
    $cover_request = drupal_http_request($url);
    if ($cover_request->code != 200) {
      return NULL;
    } else {
      return $url;
    }
  }

  /**
  * Function InetBookSearch::search_ttl
  *
  * @param string $isbn
  *   ISBN code to search
  * @return hash
  *   hash data of book
  */
  static function search_ttl($isbn) {
    libxml_use_internal_errors(true);
    $book = array();

    $url = 'https://www.todostuslibros.com';
    #print "----------------> " . $url;
    $book_data = drupal_http_request(sprintf("%s/busquedas/?keyword=%s", $url, $isbn));
    #print "\n------------------> ".$book_data."\n";

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->loadHTML($book_data->data);

    $xpath = new DOMXpath($dom);
    $books = $xpath->query("//div[@class='details']//h2//a");

    if ($books->length > 0) {
      #dpm("TENEMOS EL ENLACE AL LIBRO!");
      #print("\n------------------> TENEMOS EL ENLACE AL LIBRO\n");
      #var_dump($books->item(0)->nodeValue);
      #var_dump($books->item(0)->getAttribute('href'));
      $book_path = $books->item(0)->getAttribute('href');
      $book_data =  file_get_contents($url."/".$book_path);
      #print "\n------------------> ".$book_data."\n";
      $dom = new DOMDocument();
      $dom->preserveWhiteSpace = false;
      $dom->loadHTML($book_data);
      $xpath = new DOMXpath($dom);

      # Get book description
      $element = $xpath->query("//p[@itemprop='description']");
      if ( $element->length > 0 ) {
        $tmp_value = $element->item(0)->nodeValue;
        if ($tmp_value != "Información no disponible") {
          $book['*description'] = nl2br($tmp_value, false);
        }
      }

      # Get book cover
      $element = $xpath->query("//img[@class='portada']");
      if ( $element->length > 0 ) {
        $tmp_value = $element->item(0)->getAttribute('src');
        if ($tmp_value != "/img/nodisponible.gif") {
          $book['*cover_url'] = $tmp_value;
        }
      }

      # Get book author name
      $element = $xpath->query("//h2[@class='author']//a");
      if ( $element->length > 0 ) {
        $tmp_value = $element->item(0)->nodeValue;
        if ($tmp_value != "Información no disponible") {
          $authors = self::get_multiple_authors($tmp_value);
          foreach($authors as $author) {
            $author_name = trim($author);
            $book['*author'][] = array('name' => $author_name);
          }
        }
      }

      # Get book author info
      $element = $xpath->query("//div[@id='container']//div[@class='span20']//p");
      if ( $element->length > 0 ) {
        $tmp_value = $element->item(0)->nodeValue;
        if ($tmp_value != "Información sobre el autor no disponible") {
          if ( count($book['*author']) == 1 ) {
            $book['*author'][0]['description'] = nl2br($tmp_value, false);
          }
        }
      }

      # Get number of pages
      $element = $xpath->query("//dd[@itemprop='numberOfPages']");
      if ( $element->length > 0 ) {
        $tmp_value = $element->item(0)->nodeValue;
        if ($tmp_value) {
          $book['*pages'] = $tmp_value;
        }
      }

      # Get publication date
      $element = $xpath->query("//dd[@class='publication-date']");
      if ( $element->length > 0 ) {
        $tmp_value = $element->item(0)->nodeValue;
        if ($tmp_value) {
          $book['*edition_date'] = $tmp_value;
        }
      }

      # Get format
      $element = $xpath->query("//dd[@itemprop='bookFormat']");
      if ( $element->length > 0 ) {
        $tmp_value = $element->item(0)->nodeValue;
        if ($tmp_value) {
          $book['*format'] = $tmp_value;
        }
      }

    }
    return $book;
  }

  static function get_multiple_authors($string) {
    $authors = explode(";", $string);
    if (count($authors) == 1) {
      $authors = explode("/", $string);
    }
    return $authors;
  }

}
