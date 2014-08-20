<?php

class JSON_API_Search_Term {

  var $index;        // Integer
  var $incorrect_term;      // String
  var $replacement_term;    // String

  function JSON_API_Search_Term($wp_search_term = null, $index = 0) {
    if ($wp_search_term) {
      $this->import_wp_object($wp_search_term, $index);
    }
  }

  function import_wp_object($wp_search_term, $index) {
    $this->index = (int) $index;
    $this->incorrect_term = $wp_search_term['incorrect_term'];
    $this->replacement_term = $wp_search_term['replacement_term'];
  }


}

?>
