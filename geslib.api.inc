<?php

include_once dirname(__FILE__) . '/lib/Encoding.php';

/**
  * Process geslib line and insert it in $elements array
  *
  * @param $line
  *     CVS line from geslib file 
  */
function geslib_process_line($line, &$geslib_data) {
  switch ($line[0]) {
      # Editoriales
    case "1L":
      if ($line[1] == 'B') {
        $item['action'] = 'delete';
        $item['geslib_id'] = $line[2];
      }
      else {
        if ($line[1] == 'A')
          $item['action'] = 'create';
        elseif ($line[1] == 'M')
          $item['action'] = 'update';
        $item['title'] = geslib_utf8_encode($line[3]);
        $item['type'] = 'publisher';
        $item['geslib_id'] = $line[2];
        $geslib_data[] = $item;
      }
      break;
    //Materias
    case "3":
      if ($line[1] == 'B') {
        $item['action'] = 'delete';
        $item['geslib_id'] = $line[2];
      }
      else {
        if ($line[1] == 'A')
          $item['action'] = 'create';
        elseif ($line[1] == 'M')
          $item['action'] = 'update';
        
        $item["geslib_id"] = $line[2];
        $item['name'] = geslib_utf8_encode($line[3]);
        $item['type'] = 'category';
        $geslib_data[] = $item; 
      }
      break;
    case 'AUT':
      if ($line[1] == 'B') {
        $item['action'] = 'delete';
        $item['geslib_id'] = $line[2];
      }
      else {
        if ($line[1] == 'A')
          $item['action'] = 'create';
        elseif ($line[1] == 'M')
          $item['action'] = 'update';
        
        $item["geslib_id"] = $line[2];
        $item['autor'] = geslib_utf8_encode($line[3]);
        $item['type'] = 'author';
        $geslib_data[] = $item; 
      }
      break;
    # eBooks (igual que articulos)
    case "EB":
    # Articulos
    case "GP4":
      if ($line[1] == 'B') {
        $item['action'] = 'delete';
        $item['geslib_id'] = $line[2];
      }
      else {
        if ($line[1] == 'A')
          $item['action'] = 'create';
        elseif ($line[1] == 'M')
          $item['action'] = 'update';
        $item['type'] = 'product';
        $item['geslib_id'] = $line[2];
        $item['title'] = geslib_utf8_encode($line[3]);
        $item['price'] = str_replace(",", "", $line[29]);
        $item["isbn"] = geslib_utf8_encode($line[6]);	//ISBN (por si se quiere seleccionar)
        $item["ean"] = geslib_utf8_encode(str_replace('-','',$line[6])); 

        $item["pages"] = $line[8];
        $item["edition"] = geslib_utf8_encode($line[9]);
        $item["origen_edicion"] = geslib_utf8_encode($line[10]);
        $item["edition_date"] = $line[11];
        $item["fecha_reedicion"] = $line[12];
        $item["year"] = $line[13];
        $item["ano_ultima_edicion"] = $line[14];
        $item["location"] = geslib_utf8_encode($line[15]);
        $item["stock"] = intval($line[16]);
        $item["materia"] = $line[17];
        $item["registration_date"] = $line[18];
        $item["language"] = intval($line[20]);
        $item["subtitle"] = geslib_utf8_encode($line[26]);
        $item["status"] = $line[27];
        # Collection code is relative to publisher, so internal code should include it
        //$item["collection"][] = array("gid" => $line[32] . "_" . $line[24]);
        //$item["tmr"] = $line[28];
        //$item["list_price"] = str_replace(",", ".", $line[29]);	// PVP
        //$item["sell_price"] = str_replace(",", ".", $line[29]);	// PVP
        //$item["type"] = $line[30];
        $item["classification"] = $line[31];
        $item["publisher"] =  $line[32];
        $item["cost"] = str_replace(",", ".", $line[33]);
        $item["weight"] = $line[35];
        $item["width"] = $line[36];
        $item["length"] = $line[37];
        $item["length_unit"] = "cm";
        $item["alt_location"] = geslib_utf8_encode($line[41]);
        $item["vat"] = $line[42];
        $item["CDU"] = $line[46];
        $geslib_data[] = $item;
      }
      break;
    //Categoria del producto
//    case "5":
//      $item['type'] = 'producto_materia';
//      $item['geslib_id'] = $line[2];
//      $item['materia_id'] = $line[1];
//      $geslib_data[] = $item;
//      break;
    //Sinopsis
    case "6E":
      $item['type'] = 'description';
      $item['geslib_id'] = $line[1];
      $item['sinopsis'] = geslib_utf8_encode($line[3]);
      $geslib_data[] = $item;
      break;
    //Stock
//    case "B":
//      $item['type'] = 'stock';
//      $item['geslib_id'] = $line[1];
//      $item['stock'] = $line[2];
//      $geslib_data[] = $item;
//      break;
    case "LA":
      $item['type'] = 'product_author';
      $item['geslib_id'] = $line[1];
      $item['author_id'] = $line[2];
      $item['author_type'] = $line[3];
      $geslib_data[] = $item;
      break;
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
  
  function get_multiple_authors($string) {
    $authors = explode(";", $string);
    if (count($authors) == 1) {
      $authors = explode("/", $string);
    }
    return $authors;
  }  