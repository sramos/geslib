<?php

/**
 * @file
 * Define how to interact with geslib files
 * Info: http://www.unleashed-technologies.com/blog/2010/07/16/drupal-6-inserting-updating-nodes-programmatically
 */

include_once dirname(__FILE__) . '/Encoding.php';

class GeslibReader {

  /**
  * Read filename line by line
  *
  * @param $filename
  *	Geslib export file
  * @param $default_nom_collection
  * @param $default_nom_category
  *
  */
  function __construct($filename, $default_nom_collection=NULL, $default_nom_category=NULL) {
    $this->default_nom_collection = $default_nom_collection;
    $this->default_nom_category = $default_nom_category;
    if (empty($filename) || !file_exists ($filename)) {
      throw new Exception(t('Geslib file not valid.'));
    } else {
      $this->elements = array();
      $handle = fopen($filename, "r");
      $tmp_val = array();
      while (($data = fgetcsv($handle, 0, "|")) !== FALSE) {
        $this->process_line($data);
      }
      #$this->tracea_utf8();
    }  
  }

  function tracea_utf8() {
    set_error_handler(array("GeslibReader", "HandleError"), E_ALL);
    try {
      foreach ( $this->elements as $key1 => $object ) {
        foreach ( $object as $key2 => $value ) {
          json_encode($value);
        }
      }
    } catch (Exception $e) {
      print("---------> Problema de codificacion en $key1 con ID $key2\n");
      print_r($value);
    }
  }
  static function HandleError($code, $string, $file, $line, $context)
	{
		print "-----------> $string, $code, $file \n";
		throw new Exception($string,$code);
	}


  /**
  * Returns geslib elements
  *
  * @returns $elements
  *	Contents of geslib file elements
  */
  function &getElements() {
    return $this->elements;
  }

  /**
  * Process geslib line and insert it in $elements array
  *
  * @param $myline
  *     CVS line from geslib file 
  */
  function process_line($myline) {
    switch ($myline[0]) {
        # Usuarios
        case "";
          $this->elements["user"][$myline[2]]["action"] = $myline[1];
          if ($myline[1] != "B") {
            $this->elements["user"][$myline[2]]["name"] = $this->utf8_encode($myline[3]);
            $this->elements["user"][$myline[2]]["city"] = $this->utf8_encode($myline[4]);
            $this->elements["user"][$myline[2]]["country"] = $this->utf8_encode($myline[5]);
            $this->elements["user"][$myline[2]]["postal_code"] = $this->utf8_encode($myline[6]);
            $this->elements["user"][$myline[2]]["phone"] = $this->utf8_encode($myline[7]);
            $this->elements["user"][$myline[2]]["cif"] = $this->utf8_encode($myline[9]);
            $this->elements["user"][$myline[2]]["mail"] = $this->utf8_encode($myline[10]);
            $this->elements["user"][$myline[2]]["address"] = $this->utf8_encode($myline[11]);
            $this->elements["user"][$myline[2]]["type"] = $this->utf8_encode($myline[13]);
          }
          break;
        # Discograficas
        case "1A";
          $this->elements["music_publisher"][$myline[2]]["action"] = $myline[1];
          if ($myline[1] != "B") {
            $this->elements["music_publisher"][$myline[2]]["title"] = $this->utf8_encode($myline[4]);
          }
          break;
        # Editoriales
        case "1L":
          $this->elements["publisher"][$myline[2]]["action"] = $myline[1];
          if ($myline[1] != "B") {
            $this->elements["publisher"][$myline[2]]["title"] = $this->utf8_encode($myline[4]);
          }
          break;
        # Colecciones
        case "2":
          # Collection code is relative to publisher, so internal code should include it
          $this->elements["collection"][$myline[2] . "_" . $myline[3]]["action"] = $myline[1];
          $this->elements["collection"][$myline[2] . "_" . $myline[3]]["relation"]["publisher"][] = array("gid" => $myline[2]);
          if ($myline[1] != "B") {
            # Replace default collection name
            if ($myline[3] == "1" && utf8_encode($myline[4]) == "< Genérica >" && $this->default_nom_collection) {
              $nom_col = $this->default_nom_collection;
            } else {
              $nom_col = $this->utf8_encode($myline[4]);
            }
            $this->elements["collection"][$myline[2] . "_" . $myline[3]]["title"] = $nom_col;
          }
          break;
        # Materias
        case "3":
          $this->elements["category"][$myline[2]]["action"] = $myline[1];
          if ($myline[1] != "B") {
            # Replace default category name
            if ($myline[2] == "0" && $this->default_nom_category) {
              $nom_cat = $this->default_nom_category;
            } else {
              $nom_cat = $this->utf8_encode($myline[3]);
            }
            $this->elements["category"][$myline[2]]["title"] = $nom_cat;
          }
          break;
        # eBooks (igual que articulos)
        case "EB":
        # Articulos
        case "GP4":
          $this->elements["product"][$myline[2]]["action"] = $myline[1];
          if ($myline[1] != "B") {
            $this->elements["product"][$myline[2]]["title"] = $this->utf8_encode($myline[3]);
            $authors = InetBookSearch::get_multiple_authors($this->utf8_encode($myline[4]));
            foreach($authors as $author) {
              $author_name = trim($author);
              if ($author_name != "") {
                $this->elements["product"][$myline[2]]['*author'][] = array('name' => $author_name);
              }
            }
            #$this->elements["product"][$myline[2]]["*author"][] = array('name' => $this->utf8_encode($myline[4]));
            $this->elements["product"][$myline[2]]["attribute"]["isbn"] = $myline[6];	//ISBN (por si se quiere seleccionar)
            if ($myline[7]) {
              $this->elements["product"][$myline[2]]["attribute"]["ean"] = $myline[7];
            } else {
              $this->elements["product"][$myline[2]]["attribute"]["ean"] = str_replace('-','',$myline[6]); 
            }
            $this->elements["product"][$myline[2]]["attribute"]["pages"] = $myline[8];
            $this->elements["product"][$myline[2]]["attribute"]["edition"] = $myline[9];
            $this->elements["product"][$myline[2]]["*origen_edicion"] = $this->utf8_encode($myline[10]);
            $this->elements["product"][$myline[2]]["attribute"]["edition_date"] = $myline[11];
            $this->elements["product"][$myline[2]]["*fecha_reedicion"] = $myline[12];
            $this->elements["product"][$myline[2]]["attribute"]["year"] = $myline[13];
            $this->elements["product"][$myline[2]]["*anno_ultima_edicion"] = $myline[14];
            $this->elements["product"][$myline[2]]["attribute"]["location"] = $this->utf8_encode($myline[15]);
            $this->elements["product"][$myline[2]]["uc_product_stock"]["stock"] = intval($myline[16]);
            $this->elements["product"][$myline[2]]["*materia"] = $this->utf8_encode($myline[17]);
            $this->elements["product"][$myline[2]]["attribute"]["registration_date"] = $myline[18];
            if ( $this->elements["language"][$myline[20]] ) {
              $this->elements["product"][$myline[2]]["attribute"]["language"] = $this->elements["language"][$myline[20]];
            } else {
              $this->elements["product"][$myline[2]]["attribute"]["language"] = $myline[20];
            }
            if ( $this->elements["format"][$myline[21]] ) {
              $this->elements["product"][$myline[2]]["attribute"]["format"] = $this->elements["format"][$myline[21]];
            } else {
              $this->elements["product"][$myline[2]]["attribute"]["format"] = $myline[21];
            }
            # Collection code is relative to publisher, so internal code should include it
            $this->elements["product"][$myline[2]]["relation"]["collection"][] = array("gid" => $myline[32] . "_" . $myline[24]);
            $this->elements["product"][$myline[2]]["attribute"]["subtitle"] = $this->utf8_encode($myline[26]);
            if ( $this->elements["status"][$myline[27]] ) {
              $this->elements["product"][$myline[2]]["attribute"]["status"] = $this->elements["status"][$myline[27]];
            } else {
              $this->elements["product"][$myline[2]]["attribute"]["status"] = $myline[27];
            }
            $this->elements["product"][$myline[2]]["*tmr"] = $myline[28];
            $this->elements["product"][$myline[2]]["uc_product"]["list_price"] = str_replace(",", ".", $myline[29]);	// PVP
            $this->elements["product"][$myline[2]]["uc_product"]["sell_price"] = str_replace(",", ".", $myline[29]);	// PVP
            $this->elements["product"][$myline[2]]["type"] = $myline[30];
            $this->elements["product"][$myline[2]]["attribute"]["classification"] = $myline[31];
            $this->elements["product"][$myline[2]]["relation"]["publisher"][] = array("gid" => $myline[32]);
            $this->elements["product"][$myline[2]]["uc_product"]["cost"] = str_replace(",", ".", $myline[33]);
            $this->elements["product"][$myline[2]]["uc_product"]["weight"] = $myline[35];
            $this->elements["product"][$myline[2]]["uc_product"]["width"] = $myline[36];
            $this->elements["product"][$myline[2]]["uc_product"]["length"] = $myline[37];
            $this->elements["product"][$myline[2]]["*length_unit"] = "cm";
            if ($myline[39] != "") {
              $this->elements["product"][$myline[2]]["body"] = $this->utf8_encode($myline[39]);
            }
            $this->elements["product"][$myline[2]]["attribute"]["alt_location"] = $this->utf8_encode($myline[41]);
            $this->elements["product"][$myline[2]]["attribute"]["vat"] = $myline[42];
            $this->elements["product"][$myline[2]]["*CDU"] = $myline[46];
          }
          break;
        # Informacion eBooks
        case "IEB":
          $this->elements["product"][$myline[1]]["ebook"]["provider_ref"] = $myline[2];
          $this->elements["product"][$myline[1]]["ebook"]["tpv_ref"] = $myline[3];
          $this->elements["product"][$myline[1]]["ebook"]["size"] = $myline[4];
          $this->elements["product"][$myline[1]]["ebook"]["rights"] = $myline[5];
          $this->elements["product"][$myline[1]]["ebook"]["url"] = $myline[6];
          break;
        # Autores
        case "AUT":
          $this->elements["author"][$myline[2]]["action"] = $myline[1];
          if ($myline[1] != "B") {
            $this->elements["author"][$myline[2]]["title"] = $this->utf8_encode($myline[3]);
            $this->elements["author"][$myline[2]]["type"] = $myline[4];
          }
          break;
        # Autores asociados a los libros
        case "LA":
          $this->elements["product"][$myline[1]]["relation"]["author"][] = array("gid" => $myline[2], "function" => $myline[3]);
          break;
        # Materias asociadas a articulos
        case "5":
          $this->elements["product"][$myline[2]]["relation"]["category"][] = array("gid" => $myline[1]);
          break;
        # Referencias Libreria
        case "6":
          # Library reference code is relative to book, so internal code should include it 
          $this->elements["library_reference"][$myline[1] . "_" . $myline[2]]["title"] = $this->utf8_encode($myline[3]);
          $this->elements["library_reference"][$myline[1] . "_" . $myline[2]]["body"] = $this->utf8_encode($myline[3]);
          $this->elements["library_reference"][$myline[1] . "_" . $myline[2]]["*title_from_related_book"] = $myline[1];
          $this->elements["product"][$myline[1]]["relation"]["library_reference"][] = array("gid" => $myline[1] . "_" . $myline[2]);
          break;
        # Referencias Editor
        case "6E":
          # Publisher reference code is relative to book, so internal code should include it
	  $this->elements["publisher_reference"][$myline[1] . "_" . $myline[2]]["title"] = $this->utf8_encode($myline[3]);
          $this->elements["publisher_reference"][$myline[1] . "_" . $myline[2]]["body"] = $this->utf8_encode($myline[3]);
          $this->elements["publisher_reference"][$myline[1] . "_" . $myline[2]]["*title_from_related_book"] = $myline[1];
          $this->elements["product"][$myline[1]]["relation"]["publisher_reference"][] = array("gid" => $myline[1] . "_" . $myline[2]);
          break;
        # Indice del articulo
        case "6I":
          # Index code is relative to book, so internal code should include it
          $this->elements["index"][$myline[1] . "_" . $myline[2]]["title"] = $this->utf8_encode($myline[3]);
          $this->elements["index"][$myline[1] . "_" . $myline[2]]["body"] = $this->utf8_encode($myline[3]);
          $this->elements["index"][$myline[1] . "_" . $myline[2]]["*title_from_related_book"] = $myline[1];
          $this->elements["product"][$myline[1]]["relation"]["index"][] = array("gid" => $myline[1] . "_" . $myline[2]);
          break;
        # Format
        case "7":
          $this->elements["format"][$myline[1]] = $this->utf8_encode($myline[2]);
          break;
        case "8":
          $this->elements["language"][$myline[1]] = $this->utf8_encode($myline[2]);
          break;
        # Modificacion del stock de un producto
        case "B":
          $this->elements["product"][$myline[1]]["uc_product_stock"]["stock"] = intval($myline[2]);
          break;
        # Estados de los articulos
        case "E":
          $this->elements["status"][$myline[1]] = $this->utf8_encode($myline[2]);
          break;
        # Tipos de articulos
        case "TIPART":
          $this->elements["type"][$myline[1]] = $this->utf8_encode($myline[2]);
          break;
        default:
          #print_r($line);
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

