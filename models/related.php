<?php

class JSON_API_Related {

  var $id;              // Integer
  var $type;            // String
  var $slug;            // String
  var $url;             // String
  var $title;           // String
  var $attachments;     // Array of objects
  var $comment_count;   // Integer

  function JSON_API_Related($wp_post = null) {
    if (!empty($wp_post)) {
      $this->import_wp_object($wp_post);
    }
    do_action("json_api_{$this->type}_constructor", $this);
  }

  function import_wp_object($wp_post) {
    $this->id = (int) $wp_post->ID;
    setup_postdata($wp_post);
    $this->set_value('type', $wp_post->post_type);
    $this->set_value('slug', $wp_post->post_name);
    $this->set_value('url', get_permalink($this->id));
    $this->set_value('title', get_the_title($this->id));
    $this->set_attachments_value();
    $this->set_value('comment_count', (int) $wp_post->comment_count);
    do_action("json_api_import_wp_post", $this, $wp_post);
  }

  function set_value($key, $value) {
    global $json_api;
    if ($json_api->include_value($key)) {
      $this->$key = $value;
    } else {
      unset($this->$key);
    }
  }

  function set_attachments_value() {
    global $json_api;
    if ($json_api->include_value('attachments')) {
      $this->attachments = $json_api->introspector->get_attachments($this->id);
    } else {
      unset($this->attachments);
    }
  }

}

?>
