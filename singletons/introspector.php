<?php

class JSON_API_Introspector {

  public function get_posts($query = false, $wp_posts = false) {
    global $post, $wp_query;
    $this->set_posts_query($query);
    $output = array();
    while (have_posts()) {
      the_post();
      if ($wp_posts) {
        $new_post = $post;
      } else {
        if ($post->post_type == 'product') {
          $new_post = new JSON_API_Product($post);
        } else {
          $new_post = new JSON_API_Post($post);
        }
      }
      $output[] = $new_post;
    }
    return $output;
  }

  public function get_date_archive_permalinks() {
    $archives = wp_get_archives('echo=0');
    preg_match_all("/href='([^']+)'/", $archives, $matches);
    return $matches[1];
  }

  public function get_date_archive_tree($permalinks) {
    $tree = array();
    foreach ($permalinks as $url) {
      if (preg_match('#(\d{4})/(\d{2})#', $url, $date)) {
        $year = $date[1];
        $month = $date[2];
      } else if (preg_match('/(\d{4})(\d{2})/', $url, $date)) {
        $year = $date[1];
        $month = $date[2];
      } else {
        continue;
      }
      $count = $this->get_date_archive_count($year, $month);
      if (empty($tree[$year])) {
        $tree[$year] = array(
          $month => $count
        );
      } else {
        $tree[$year][$month] = $count;
      }
    }
    return $tree;
  }

  public function get_date_archive_count($year, $month) {
    if (!isset($this->month_archives)) {
      global $wpdb;
      $post_counts = $wpdb->get_results("
        SELECT DATE_FORMAT(post_date, '%Y%m') AS month,
               COUNT(ID) AS post_count
        FROM $wpdb->posts
        WHERE post_status = 'publish'
          AND post_type = 'post'
        GROUP BY month
      ");
      $this->month_archives = array();
      foreach ($post_counts as $post_count) {
        $this->month_archives[$post_count->month] = $post_count->post_count;
      }
    }
    return $this->month_archives["$year$month"];
  }

  public function get_categories($args = null) {
    $wp_categories = get_categories($args);
    $categories = array();
    foreach ($wp_categories as $wp_category) {
      if ($wp_category->term_id == 1 && $wp_category->slug == 'uncategorized') {
        continue;
      }
      $categories[] = $this->get_category_object($wp_category);
    }
    return $categories;
  }

  public function get_current_post() {
    global $json_api;
    extract($json_api->query->get(array('id', 'slug', 'post_id', 'post_slug')));
    if ($id || $post_id) {
      if (!$id) {
        $id = $post_id;
      }
      $posts = $this->get_posts(array(
        'p' => $id
      ), true);
    } else if ($slug || $post_slug) {
      if (!$slug) {
        $slug = $post_slug;
      }
      $posts = $this->get_posts(array(
        'name' => $slug
      ), true);
    } else {
      $json_api->error("Include 'id' or 'slug' var in your request.");
    }
    if (!empty($posts)) {
      return $posts[0];
    } else {
      return null;
    }
  }

  public function get_current_category() {
    global $json_api;
    extract($json_api->query->get(array('id', 'slug', 'category_id', 'category_slug')));
    if ($id || $category_id) {
      if (!$id) {
        $id = $category_id;
      }
      return $this->get_category_by_id($id);
    } else if ($slug || $category_slug) {
      if (!$slug) {
        $slug = $category_slug;
      }
      return $this->get_category_by_slug($slug);
    } else {
      $json_api->error("Include 'id' or 'slug' var in your request.");
    }
    return null;
  }

  public function get_category_by_id($category_id) {
    $wp_category = get_term_by('id', $category_id, 'category');
    return $this->get_category_object($wp_category);
  }

  public function get_category_by_slug($category_slug) {
    $wp_category = get_term_by('slug', $category_slug, 'category');
    return $this->get_category_object($wp_category);
  }

  public function get_tags() {
    $wp_tags = get_tags();
    return array_map(array(&$this, 'get_tag_object'), $wp_tags);
  }

  public function get_terms($taxonomies, $args) {
    $wp_terms = get_terms( $taxonomies, $args );
    return array_map(array(&$this, 'get_tag_object'), $wp_terms);
  }

  public function get_current_tag() {
    global $json_api;
    extract($json_api->query->get(array('id', 'slug', 'tag_id', 'tag_slug')));
    if ($id || $tag_id) {
      if (!$id) {
        $id = $tag_id;
      }
      return $this->get_tag_by_id($id);
    } else if ($slug || $tag_slug) {
      if (!$slug) {
        $slug = $tag_slug;
      }
      return $this->get_tag_by_slug($slug);
    } else {
      $json_api->error("Include 'id' or 'slug' var in your request.");
    }
    return null;
  }

  public function get_tag_by_id($tag_id) {
    $wp_tag = get_term_by('id', $tag_id, 'post_tag');
    return $this->get_tag_object($wp_tag);
  }

  public function get_tag_by_slug($tag_slug) {
    $wp_tag = get_term_by('slug', $tag_slug, 'post_tag');
    return $this->get_tag_object($wp_tag);
  }

  public function get_authors() {
    global $wpdb;
    $author_ids = $wpdb->get_col("
      SELECT u.ID, m.meta_value AS last_name
      FROM $wpdb->users AS u,
           $wpdb->usermeta AS m
      WHERE m.user_id = u.ID
        AND m.meta_key = 'last_name'
      ORDER BY last_name
    ");
    $all_authors = array_map(array(&$this, 'get_author_by_id'), $author_ids);
    $active_authors = array_filter($all_authors, array(&$this, 'is_active_author'));
    return $active_authors;
  }

  public function get_current_author() {
    global $json_api;
    extract($json_api->query->get(array('id', 'slug', 'author_id', 'author_slug')));
    if ($id || $author_id) {
      if (!$id) {
        $id = $author_id;
      }
      return $this->get_author_by_id($id);
    } else if ($slug || $author_slug) {
      if (!$slug) {
        $slug = $author_slug;
      }
      return $this->get_author_by_login($slug);
    } else {
      $json_api->error("Include 'id' or 'slug' var in your request.");
    }
    return null;
  }

  public function get_author_by_id($id) {
    $id = get_the_author_meta('ID', $id);
    if (!$id) {
      return null;
    }
    return new JSON_API_Author($id);
  }

  public function get_author_by_login($login) {
    global $wpdb;
    $id = $wpdb->get_var($wpdb->prepare("
      SELECT ID
      FROM $wpdb->users
      WHERE user_nicename = %s
    ", $login));
    return $this->get_author_by_id($id);
  }

  public function get_comments($post_id) {
    global $wpdb;
    $wp_comments = $wpdb->get_results($wpdb->prepare("
      SELECT *
      FROM $wpdb->comments
      WHERE comment_post_ID = %d
        AND comment_approved = 1
        AND comment_type = ''
      ORDER BY comment_date
    ", $post_id));
    $comments = array();
    foreach ($wp_comments as $wp_comment) {
      $comments[] = new JSON_API_Comment($wp_comment);
    }
    return $comments;
  }

  public function get_attachments($post_id) {
    $wp_attachments = get_children(array(
      'post_type' => 'attachment',
      'post_parent' => $post_id,
      'orderby' => 'menu_order',
      'order' => 'ASC',
      'suppress_filters' => false
    ));
    $attachments = array();
    if (!empty($wp_attachments)) {
      foreach ($wp_attachments as $wp_attachment) {
        $attachments[] = new JSON_API_Attachment($wp_attachment);
      }
    }
    return $attachments;
  }

  public function get_attachment($attachment_id) {
    global $wpdb;
    $wp_attachment = $wpdb->get_row(
      $wpdb->prepare("
        SELECT *
        FROM $wpdb->posts
        WHERE ID = %d
      ", $attachment_id)
    );
    return new JSON_API_Attachment($wp_attachment);
  }

  public function attach_child_posts(&$post) {
    $post->children = array();
    $wp_children = get_posts(array(
      'post_type' => $post->type,
      'post_parent' => $post->id,
      'order' => 'ASC',
      'orderby' => 'menu_order',
      'numberposts' => -1,
      'suppress_filters' => false
    ));
    foreach ($wp_children as $wp_post) {
      $new_post = new JSON_API_Post($wp_post);
      $new_post->parent = $post->id;
      $post->children[] = $new_post;
    }
    foreach ($post->children as $child) {
      $this->attach_child_posts($child);
    }
  }

  protected function get_category_object($wp_category) {
    if (!$wp_category) {
      return null;
    }
    return new JSON_API_Category($wp_category);
  }

  protected function get_tag_object($wp_tag) {
    if (!$wp_tag) {
      return null;
    }
    return new JSON_API_Tag($wp_tag);
  }

  protected function is_active_author($author) {
    if (!isset($this->active_authors)) {
      $this->active_authors = explode(',', wp_list_authors(array(
        'html' => false,
        'echo' => false,
        'exclude_admin' => false
      )));
      $this->active_authors = array_map('trim', $this->active_authors);
    }
    return in_array($author->name, $this->active_authors);
  }

  protected function set_posts_query($query = false) {
    global $json_api, $wp_query;

    if (!$query) {
      $query = array();
    }

    $query = array_merge($query, $wp_query->query);

    if ($json_api->query->orderby) {
      $query['orderby'] = $json_api->query->orderby;
    }

    if ($json_api->query->page) {
      $query['paged'] = $json_api->query->page;
    }

    if ($json_api->query->count) {
      $query['posts_per_page'] = $json_api->query->count;
    }

    if ($json_api->query->post_type) {
      $query['post_type'] = $json_api->query->post_type;
    }

    if ($json_api->query->category) {
      $query['category_name'] = $json_api->query->category;
    }

    if ($json_api->query->category_exclude) {
      $category = get_category_by_slug($json_api->query->category_exclude);
      $id = $category->term_id;
      $query['category__not_in'] = $id;
    }

    if ($json_api->query->product_type) {
      $query['product_type'] = $json_api->query->product_type;
    }

    if ($json_api->query->tags) {
      $query['tag'] = $json_api->query->tags;
    }

    if ($json_api->query->product_tags) {
      $query['product_tag'] = $json_api->query->product_tags;
    }

    if (!empty($query)) {
      query_posts($query);
      do_action('json_api_query', $wp_query);
    }
  }

  // LBL

  public function get_related_posts($post_id) {
    $acf_related_posts = get_field('related_posts', $post_id);
    $related_posts = array();
    if (!empty($acf_related_posts)) {
      foreach ($acf_related_posts as $acf_related_post) {
        $related_posts[] = new JSON_API_Related($acf_related_post);
      }
    }
    return $related_posts;
  }

  public function get_related_products($post_id) {
    $acf_related_products = get_field('product_alternates', $post_id);
    $related_products = array();
    if (!empty($acf_related_products)) {
      foreach ($acf_related_products as $acf_related_product) {
        $related_products[] = new JSON_API_Related($acf_related_product);
      }
    }
    return $related_products;
  }

  public function get_related_styleboards($post_id) {
    $acf_related_styleboards = get_field('product_boards', $post_id);
    $related_styleboards = array();
    if (!empty($acf_related_styleboards)) {
      foreach ($acf_related_styleboards as $acf_related_styleboard) {
        $related_styleboards[] = new JSON_API_Related($acf_related_styleboard);
      }
    }
    return $related_styleboards;
  }

  public function get_colors($post_id) {
    $acf_colors = get_field('colors', $post_id);
    $acf_search_colors = get_field('search_colors', $post_id);
    $colors = array();
    if (!empty($acf_colors)) {
      foreach ($acf_colors as $acf_color) {
        $colors[] = new JSON_API_Color($acf_color);
      }
    }
    if (!empty($acf_search_colors)) {
      foreach ($acf_search_colors as $acf_color) {
        $colors[] = new JSON_API_Color($acf_color);
      }
    }
    return $colors;
  }

  public function get_styleboard_products($post_id) {
    $acf_styleboard_products = get_field('styleboard_products', $post_id);
    $styleboard_products = array();
    if (!empty($acf_styleboard_products)) {
      foreach ($acf_styleboard_products as $key=>$acf_styleboard_product) {
        $styleboard_products[] = new JSON_API_Board_Item($acf_styleboard_product, $key);
      }
    }
    return $styleboard_products;
  }

  public function get_search_terms($post_id) {
    $acf_search_terms = get_field('search_terms', $post_id);
    $search_terms = array();
    if (!empty($acf_search_terms)) {
      foreach ($acf_search_terms as $key=>$acf_search_term) {
        $search_terms[] = new JSON_API_Search_Term($acf_search_term, $key);
      }
    }
    return $search_terms;
  }

  public function get_nb_links($post_id) {
    $nb_links = array();
    $extNet = $this->nb_getLinks($post_id);
    if (!empty($extNet)) {
      foreach ($extNet as $link) {
        $nb_links[] = array( 'link' => $link['uri'], 'title' => $link['uri_title'] );
      }
    }
    return $nb_links;
  }

  public function nb_getLinks( $postID, $output = ARRAY_A )
  {
    global $wpdb;
    $query = "
      SELECT * FROM `wp_netblog_ext` e, `wp_netblog_rel_extnd` r
      WHERE r.uri_id = e.uri_id
      AND r.id = '$postID'
      ORDER BY e.uri
    ";
    return $wpdb->get_results($query, $output);
  }
}

?>
