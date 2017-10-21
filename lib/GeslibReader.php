<?php

/**
 * @file
 * Define how to interact with geslib files
 * Info: http://www.unleashed-technologies.com/blog/2010/07/16/drupal-6-inserting-updating-nodes-programmatically
 *
 * @author   "Santiago Ramos" <sramos@sitiodistinto.net>
 * @package  Geslib
 */

include_once dirname(__FILE__) . '/GeslibCommon.php';
include_once dirname(__FILE__) . '/InetBookSearch.php';
include_once dirname(__FILE__) . '/DilveSearch.php';

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
        $this->read_line($data);
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
  * Read geslib line and insert it in $elements array
  *
  * @param $myline
  *     CVS line from geslib file
  */
  function read_line($myline) {
    $item = array();
    switch ($myline[0]) {
        # Usuarios
        case "";
          $this->elements["user"][$myline[2]]["action"] = $myline[1];
          if ($myline[1] != "B") {
            $this->elements["user"][$myline[2]]["name"] = GeslibCommon::utf8_encode($myline[3]);
            $this->elements["user"][$myline[2]]["city"] = GeslibCommon::utf8_encode($myline[4]);
            $this->elements["user"][$myline[2]]["country"] = GeslibCommon::utf8_encode($myline[5]);
            $this->elements["user"][$myline[2]]["postal_code"] = GeslibCommon::utf8_encode($myline[6]);
            $this->elements["user"][$myline[2]]["phone"] = GeslibCommon::utf8_encode($myline[7]);
            $this->elements["user"][$myline[2]]["cif"] = GeslibCommon::utf8_encode($myline[9]);
            $this->elements["user"][$myline[2]]["mail"] = GeslibCommon::utf8_encode($myline[10]);
            $this->elements["user"][$myline[2]]["address"] = GeslibCommon::utf8_encode($myline[11]);
            $this->elements["user"][$myline[2]]["type"] = GeslibCommon::utf8_encode($myline[13]);
          }
          break;
        # Discograficas
        case "1A";
          $this->elements["music_publisher"][$myline[2]]["action"] = $myline[1];
          if ($myline[1] != "B") {
            $this->elements["music_publisher"][$myline[2]]["title"] = GeslibCommon::utf8_encode($myline[4]);
          }
          break;
        # Editoriales
        case "1L":
          $this->elements["publisher"][$myline[2]]["action"] = $myline[1];
          if ($myline[1] != "B") {
            $this->elements["publisher"][$myline[2]]["title"] = GeslibCommon::utf8_encode($myline[4]);
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
              $nom_col = GeslibCommon::utf8_encode($myline[4]);
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
              $nom_cat = GeslibCommon::utf8_encode($myline[3]);
            }
            $this->elements["category"][$myline[2]]["title"] = $nom_cat;
          }
          break;
        # eBooks (igual que articulos)
        case "EB":
        # Articulos
        # GP4|M|3913|LA GUERRA SEGÚN SIMONE WEIL|LARRAURI GÓMEZ, MAITE|2489|978-84-8131-427-4|9788481314274|108|01|VALENCIA|20021101||    |2002||1|27|20061127||1|||MAX (CAPDEVILA GISBERT, FRANCESC)|1|4||0|7,00|14,96|L0|1|1038|14,38||191|140|200|||||4,00|||0,00|HPC||||N|N||3931|||1||N|
        case "GP4":
          $this->elements["book"][$myline[2]]["action"] = $myline[1];
          if ($myline[1] != "B") {
            $this->elements["book"][$myline[2]]["title"] = GeslibCommon::utf8_encode($myline[3]);
            $authors = InetBookSearch::get_multiple_authors(GeslibCommon::utf8_encode($myline[4]));
            foreach($authors as $author) {
              $author_name = trim($author);
              if ($author_name != "") {
                $this->elements["book"][$myline[2]]['*author'][] = array('name' => $author_name);
              }
            }
            #$this->elements["book"][$myline[2]]["*author"][] = array('name' => GeslibCommon::utf8_encode($myline[4]));
            //ISBN (por si se quiere seleccionar)
            $this->elements["book"][$myline[2]]["attribute"]["isbn"] = GeslibCommon::utf8_encode($myline[6]);
            # Si hay EAN, lo asignamos y tambien al model de UC
            if ($myline[7]) {
              $code = GeslibCommon::utf8_encode($myline[7]);
              $this->elements["book"][$myline[2]]["attribute"]["ean"] = $code;
              $this->elements["book"][$myline[2]]["uc_product"]["model"] = $code;
            # Si no hay EAN pero si ISBN, cogemos el ISBN sin guiones
            } else if ($myline[6]) {
              $code = GeslibCommon::utf8_encode($myline[6]);
              $this->elements["book"][$myline[2]]["attribute"]["ean"] = str_replace('-','',$code);
              $this->elements["book"][$myline[2]]["uc_product"]["model"] = str_replace('-','',$code);
            # y si tampoco hay ISBN, tomamos el GID como model UC
            } else {
              $this->elements["book"][$myline[2]]["uc_product"]["model"] = "GID-".$myline[2];
            }
            $this->elements["book"][$myline[2]]["attribute"]["pages"] = $myline[8];
            $this->elements["book"][$myline[2]]["attribute"]["edition"] = $myline[9];
            $this->elements["book"][$myline[2]]["*origen_edicion"] = GeslibCommon::utf8_encode($myline[10]);
            $this->elements["book"][$myline[2]]["attribute"]["edition_date"] = $myline[11];
            $this->elements["book"][$myline[2]]["*fecha_reedicion"] = $myline[12];
            $this->elements["book"][$myline[2]]["attribute"]["year"] = $myline[13];
            $this->elements["book"][$myline[2]]["*anno_ultima_edicion"] = $myline[14];
            $this->elements["book"][$myline[2]]["attribute"]["location"] = GeslibCommon::utf8_encode($myline[15]);
            $this->elements["book"][$myline[2]]["uc_product"]["stock"] = intval($myline[16]);
            $this->elements["book"][$myline[2]]["*materia"] = GeslibCommon::utf8_encode($myline[17]);
            $this->elements["book"][$myline[2]]["attribute"]["registration_date"] = $myline[18];
            if ( $this->elements["language"][$myline[20]] ) {
              $this->elements["book"][$myline[2]]["attribute"]["language"] = $this->elements["language"][$myline[20]];
            } else {
              $this->elements["book"][$myline[2]]["attribute"]["language"] = $myline[20];
            }
            if ( $this->elements["format"][$myline[21]] ) {
              $this->elements["book"][$myline[2]]["attribute"]["format"] = $this->elements["format"][$myline[21]];
            } else {
              $this->elements["book"][$myline[2]]["attribute"]["format"] = $myline[21];
            }
            # Collection code is relative to publisher, so internal code should include it
            $this->elements["book"][$myline[2]]["relation"]["collection"][] = array("gid" => $myline[32] . "_" . $myline[24]);
            $this->elements["book"][$myline[2]]["attribute"]["subtitle"] = GeslibCommon::utf8_encode($myline[26]);
            if ( $this->elements["status"][$myline[27]] ) {
              $this->elements["book"][$myline[2]]["attribute"]["status"] = $this->elements["status"][$myline[27]];
            } else {
              $this->elements["book"][$myline[2]]["attribute"]["status"] = $myline[27];
            }
            $this->elements["book"][$myline[2]]["*tmr"] = $myline[28];
            $this->elements["book"][$myline[2]]["uc_product"]["list_price"] = str_replace(",", ".", $myline[29]);	// PVP
            $this->elements["book"][$myline[2]]["uc_product"]["sell_price"] = str_replace(",", ".", $myline[29]);	// PVP
            $this->elements["book"][$myline[2]]["type"] = $myline[30];
            $this->elements["book"][$myline[2]]["attribute"]["classification"] = $myline[31];
            $this->elements["book"][$myline[2]]["relation"]["publisher"][] = array("gid" => $myline[32]);
            $this->elements["book"][$myline[2]]["uc_product"]["cost"] = str_replace(",", ".", $myline[33]);
            $this->elements["book"][$myline[2]]["uc_product"]["weight"] = $myline[35];
            $this->elements["book"][$myline[2]]["uc_product"]["weight_units"] = "g";
            $this->elements["book"][$myline[2]]["uc_product"]["width"] = $myline[36];
            $this->elements["book"][$myline[2]]["uc_product"]["length"] = $myline[37];
            $this->elements["book"][$myline[2]]["uc_product"]["length_units"] = "cm";
            $this->elements["book"][$myline[2]]["uc_product"]["default_qty"] = 1;
            $this->elements["book"][$myline[2]]["uc_product"]["pkg_qty"] = 1;
            if ($myline[39] != "") {
              $this->elements["book"][$myline[2]]["attribute"]["body"] = GeslibCommon::utf8_encode($myline[39]);
            }
            $this->elements["book"][$myline[2]]["attribute"]["alt_location"] = GeslibCommon::utf8_encode($myline[41]);
            $this->elements["book"][$myline[2]]["attribute"]["vat"] = $myline[42];
            $this->elements["book"][$myline[2]]["*CDU"] = $myline[46];
          }
          break;
        # Informacion eBooks
        case "IEB":
          $this->elements["book"][$myline[1]]["ebook"]["provider_ref"] = $myline[2];
          $this->elements["book"][$myline[1]]["ebook"]["tpv_ref"] = $myline[3];
          $this->elements["book"][$myline[1]]["ebook"]["size"] = $myline[4];
          $this->elements["book"][$myline[1]]["ebook"]["rights"] = $myline[5];
          $this->elements["book"][$myline[1]]["ebook"]["url"] = $myline[6];
          break;
        # Autores
        # AUT|A|42671|BOIE, KIRSTEN|
        case "AUT":
          $this->elements["author"][$myline[2]]["action"] = $myline[1];
          if ($myline[1] != "B") {
            $this->elements["author"][$myline[2]]["title"] = GeslibCommon::utf8_encode($myline[3]);
            $this->elements["author"][$myline[2]]["type"] = $myline[4];
          }
          break;
        # Descripcion del autor
        # AUTBIO|42671|Kirsten Boie (Hamburgo, 1950) es una de las autoras de libros ...|
        case "AUTBIO":
          $this->elements["author"][$myline[1]]["attribute"]["body"] = GeslibCommon::utf8_encode($myline[2]);
          break;
        # Autores asociados a los libros
        # LA|48253|2807|A|1|
        # LA|43071|32477|T|1|
        # A: Autor
        # T: Traductor
        # I: Ilustrador
        # IC: Ilustrador contraportada
        # IP: Ilustrador portada
        case "LA":
          $this->elements["book"][$myline[1]]["relation"]["author"][] = array("gid" => $myline[2], "function" => $myline[3]);
          break;
        # Materias asociadas a articulos
        # 5|27|50667|
        case "5":
          $this->elements["book"][$myline[2]]["relation"]["category"][] = array("gid" => $myline[1]);
          break;
        # BIC|50879|2ADS|Español / Castellano|
        # BIC|50879|3JJ|Siglo xx|
        # BIC|50879|3JK|Siglo xx: España|
        # BIC|50879|3JM|Siglo xxi|
        # BIC|50879|HBTV4|Revolución rusa|
        # BIC|50879|HBTW|La guerra fría|
        # BIC|50879|JPFC|Marxismo y comunismo|
        # BIC|50879|JPFF|Socialismo e ideologías democráticas de centro izquierda|
        # BIC|50667|JPL|Partidos políticos|
        # BIC|50667|JPWF|Manifestaciones y movimientos de protesta|
        case "BIC":
          $this->elements["book"][$myline[1]]["relation"]["tags"][] = array("code" => $myline[2], "body" => $myline[3]);
          break;
        # Referencias Libreria
        case "6":
          # Library reference code is relative to book, so internal code should include it
          $descr = GeslibCommon::utf8_encode($myline[3]);
          #$this->elements["library_reference"][$myline[1] . "_" . $myline[2]]["title"] = $descr;
          $this->elements["library_reference"][$myline[1] . "_" . $myline[2]]["title"] = "6 ".$myline[1] . "-" . $myline[2];
          $this->elements["library_reference"][$myline[1] . "_" . $myline[2]]["attribute"]["body"] = $descr;
          $this->elements["library_reference"][$myline[1] . "_" . $myline[2]]["*title_from_related_book"] = $myline[1];
          $this->elements["book"][$myline[1]]["relation"]["library_reference"][] = array("gid" => $myline[1] . "_" . $myline[2]);
          break;
        # Referencias Editor
        # 6E|48794|1|Palabra de moda, "populismo" significa cosas ...|
        case "6E":
          # Publisher reference code is relative to book, so internal code should include it
          $descr = GeslibCommon::utf8_encode($myline[3]);
          #$this->elements["publisher_reference"][$myline[1] . "_" . $myline[2]]["title"] = $descr;
	        $this->elements["publisher_reference"][$myline[1] . "_" . $myline[2]]["title"] = "6E ".$myline[1] . "-" . $myline[2];
          $this->elements["publisher_reference"][$myline[1] . "_" . $myline[2]]["attribute"]["body"] = $descr;
          $this->elements["publisher_reference"][$myline[1] . "_" . $myline[2]]["*title_from_related_book"] = $myline[1];
          $this->elements["book"][$myline[1]]["relation"]["publisher_reference"][] = array("gid" => $myline[1] . "_" . $myline[2]);
          break;
        # Indice del articulo
        # 6I|50879|1|I. EL CAMINO DE LA REVOLUCIÓN Y SU ESTALLIDO ... |
        case "6I":
          # Index code is relative to book, so internal code should include it
          $descr = GeslibCommon::utf8_encode($myline[3]);
          #$this->elements["index"][$myline[1] . "_" . $myline[2]]["title"] = $descr;
          $this->elements["index"][$myline[1] . "_" . $myline[2]]["title"] = "6I ".$myline[1] . "-" . $myline[2];
          $this->elements["index"][$myline[1] . "_" . $myline[2]]["attribute"]["body"] = $descr;
          $this->elements["index"][$myline[1] . "_" . $myline[2]]["*title_from_related_book"] = $myline[1];
          $this->elements["book"][$myline[1]]["relation"]["index"][] = array("gid" => $myline[1] . "_" . $myline[2]);
          break;
        # Format
        case "7":
          $this->elements["format"][$myline[1]] = GeslibCommon::utf8_encode($myline[2]);
          break;
        case "8":
          $this->elements["language"][$myline[1]] = GeslibCommon::utf8_encode($myline[2]);
          break;
        # Modificacion del stock de un producto
        case "B":
          $this->elements["book"][$myline[1]]["uc_product"]["stock"] = intval($myline[2]);
          break;
        # Estados de los articulos
        case "E":
          $this->elements["status"][$myline[1]] = GeslibCommon::utf8_encode($myline[2]);
          break;
        # Tipos de articulos
        case "TIPART":
          $this->elements["type"][$myline[1]] = GeslibCommon::utf8_encode($myline[2]);
          break;
        default:
          #print_r($line);
    }
  }

}
