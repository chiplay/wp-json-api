<?php

class JSON_API_Board_Item {

  var $index;        // Integer
  var $product;      // Product Model
  var $top;          // Integer
  var $left;         // Integer
  var $width;        // Integer
  var $height;       // Integer
  var $external_url; // String

  function JSON_API_Board_Item($wp_board_item = null, $index = 0) {
    if ($wp_board_item) {
      $this->import_wp_object($wp_board_item, $index);
    }
  }

  function import_wp_object($wp_board_item, $index) {
    $this->index = (int) $index;
    $this->top = (int) $wp_board_item['top'];
    $this->left = (int) $wp_board_item['left'];
    $this->width = (int) $wp_board_item['width'];
    $this->height = (int) $wp_board_item['height'];
    $this->external_url = $wp_board_item['external_url'];
    $this->product = new JSON_API_Related($wp_board_item['product'][0]);
  }


}

?>
