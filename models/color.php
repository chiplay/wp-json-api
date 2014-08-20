<?php

class JSON_API_Color {

  var $id;          // Integer
  var $slug;        // String
  var $title;       // String
  var $color;       // String
  var $paint_code;  // String
  var $color_label; // String
  var $search_term; // String

  function JSON_API_Color($wp_color = null) {
    if ($wp_color) {
      $this->import_wp_object($wp_color);
    }
  }

  function import_wp_object($wp_color) {
    $this->id = (int) $wp_color->ID;
    $this->set_value('slug', $wp_color->post_name);
    $this->set_value('title', get_the_title($this->id));
    $this->set_value('color', get_field('color', $this->id));
    $this->set_value('paint_code', get_field('paint_code', $this->id));
    $this->set_value('search_term', get_field('search_term', $this->id));
    $this->set_value('color_label', get_field('color_label', $this->id));
  }

  function set_value($key, $value) {
    global $json_api;
    $this->$key = $value;
    // if ($json_api->include_value($key)) {
    //   $this->$key = $value;
    // } else {
    //   unset($this->$key);
    // }
  }

}

?>
