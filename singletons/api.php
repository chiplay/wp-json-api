<?php

class JSON_API {

  function __construct() {
    $this->query = new JSON_API_Query();
    $this->introspector = new JSON_API_Introspector();
    $this->response = new JSON_API_Response();
    add_action('template_redirect', array(&$this, 'template_redirect'));
    add_action('admin_menu', array(&$this, 'admin_menu'));
    add_action('update_option_json_api_base', array(&$this, 'flush_rewrite_rules'));
    add_action('pre_update_option_json_api_controllers', array(&$this, 'update_controllers'));
  }

  function template_redirect() {
    // Check to see if there's an appropriate API controller + method
    $controller = strtolower($this->query->get_controller());
    $available_controllers = $this->get_controllers();
    $enabled_controllers = explode(',', get_option('json_api_controllers', 'core'));
    $active_controllers = array_intersect($available_controllers, $enabled_controllers);

    if ($controller) {

      if (empty($this->query->dev)) {
        error_reporting(0);
      }

      if (!in_array($controller, $active_controllers)) {
        $this->error("Unknown controller '$controller'.");
      }

      $controller_path = $this->controller_path($controller);
      if (file_exists($controller_path)) {
        require_once $controller_path;
      }
      $controller_class = $this->controller_class($controller);

      if (!class_exists($controller_class)) {
        $this->error("Unknown controller '$controller_class'.");
      }

      $this->controller = new $controller_class();
      $method = $this->query->get_method($controller);

      if ($method) {

        $this->response->setup();

        // Run action hooks for method
        do_action("json_api", $controller, $method);
        do_action("json_api-{$controller}-$method");

        // Error out if nothing is found
        if ($method == '404') {
          $this->error('Not found');
        }

        // Set up caching and benchmarking
        $cache_duration = get_option('json_api_cache_duration', 0);
        $requested = array( $_REQUEST, $_SERVER['REQUEST_URI'] );
        $request_md5 = md5( json_encode( $requested ) );
        $benchmarking = get_option('json_api_benchmarking', false);
        $time_start = microtime(true);
        $cached = true;

        // If caching enabled, attempt to retrieve result from cache. Evaluates to true when fails
        if ($cache_duration == 0 || false === ( $result = get_transient('json_api_' . $request_md5))) {
            $cached = false;
            // Run the method
            $result = $this->controller->$method();
            // If caching enabled, save the result
            if ($cache_duration > 0) {
                set_transient('json_api_' . $request_md5, $result, $cache_duration);
            }
        }

        // Display benchmarking results if enabled
        $time_difference = microtime(true) - $time_start;
        if ($benchmarking) {
            $result['benchmarking'] = "Response generated in $time_difference seconds " . ( $cached ? "from" : "without" ) . " cached $request_md5.";
        }

        // Handle the result
        $this->response->respond($result);

        // Done!
        exit;
      }
    }
  }

  function admin_menu() {
    add_options_page('JSON API Settings', 'JSON API', 'manage_options', 'json-api', array(&$this, 'admin_options'));
  }

  function admin_options() {
    if (!current_user_can('manage_options'))  {
      wp_die( __('You do not have sufficient permissions to access this page.') );
    }

    $available_controllers = $this->get_controllers();
    $active_controllers = explode(',', get_option('json_api_controllers', 'core'));

    if (count($active_controllers) == 1 && empty($active_controllers[0])) {
      $active_controllers = array();
    }

    if (!empty($_REQUEST['_wpnonce']) && wp_verify_nonce($_REQUEST['_wpnonce'], "update-options")) {
      if ((!empty($_REQUEST['action']) || !empty($_REQUEST['action2'])) &&
          (!empty($_REQUEST['controller']) || !empty($_REQUEST['controllers']))) {
        if (!empty($_REQUEST['action'])) {
          $action = $_REQUEST['action'];
        } else {
          $action = $_REQUEST['action2'];
        }

        if (!empty($_REQUEST['controllers'])) {
          $controllers = $_REQUEST['controllers'];
        } else {
          $controllers = array($_REQUEST['controller']);
        }

        foreach ($controllers as $controller) {
          if (in_array($controller, $available_controllers)) {
            if ($action == 'activate' && !in_array($controller, $active_controllers)) {
              $active_controllers[] = $controller;
            } else if ($action == 'deactivate') {
              $index = array_search($controller, $active_controllers);
              if ($index !== false) {
                unset($active_controllers[$index]);
              }
            }
          }
        }
        $this->save_option('json_api_controllers', implode(',', $active_controllers));
        $updated = true;
      }
      if (isset($_REQUEST['json_api_base']) && $_REQUEST['json_api_base'] != get_option('json_api_base')) {
        $this->save_option('json_api_base', $_REQUEST['json_api_base']);
        $updated = true;
      }
      if (isset($_REQUEST['json_api_cache_duration'])) {
        // Check whether value contains only numbers
        if (preg_match('_^[0-9]+$_', $_REQUEST['json_api_cache_duration'])) {
          $this->save_option('json_api_cache_duration', $_REQUEST['json_api_cache_duration']);
          $updated = true;
        } else if ( $_REQUEST['json_api_cache_duration'] == '') {
          if (get_option('json_api_cache_duration') != 0) {
            $this->save_option('json_api_cache_duration', 0);
            $updated = true;
          }
        } else {
          ?><div class="error">Caching time value can contain only numbers (0-9).</div><?php
        }
      }
      if (isset($_REQUEST['json_api_benchmarking'])) {
        $this->save_option('json_api_benchmarking', 1);
        $updated = true;
      } else if ((isset($_REQUEST) || isset($_POST)) && get_option('json_api_benchmarking') != 0) {
        $this->save_option('json_api_benchmarking', 0);
        $updated = true;
      }

      // Update notification
      if ($updated) {
        ?><div class="updated">Settings have been updated.</div><?php
      }

      // Database validity checks
      if (!preg_match('_^[0-9]+$_', get_option( 'json_api_cache_duration'))) {
        $this->save_option('json_api_cache_duration', 0);
        ?><div class="error">Caching time value in database contained invalid characters and has been reset to 0.</div><?php
      }
    }

    ?>
<div class="wrap">
  <div id="icon-options-general" class="icon32"><br /></div>
  <h2>JSON API Settings</h2>
  <form action="options-general.php?page=json-api" method="post">
    <?php wp_nonce_field('update-options'); ?>
    <h3>Controllers</h3>
    <?php $this->print_controller_actions(); ?>
    <table id="all-plugins-table" class="widefat">
      <thead>
        <tr>
          <th class="manage-column check-column" scope="col"><input type="checkbox" /></th>
          <th class="manage-column" scope="col">Controller</th>
          <th class="manage-column" scope="col">Description</th>
        </tr>
      </thead>
      <tfoot>
        <tr>
          <th class="manage-column check-column" scope="col"><input type="checkbox" /></th>
          <th class="manage-column" scope="col">Controller</th>
          <th class="manage-column" scope="col">Description</th>
        </tr>
      </tfoot>
      <tbody class="plugins">
        <?php

        foreach ($available_controllers as $controller) {

          $error = false;
          $active = in_array($controller, $active_controllers);
          $info = $this->controller_info($controller);

          if (is_string($info)) {
            $active = false;
            $error = true;
            $info = array(
              'name' => $controller,
              'description' => "<p><strong>Error</strong>: $info</p>",
              'methods' => array(),
              'url' => null
            );
          }

          ?>
          <tr class="<?php echo ($active ? 'active' : 'inactive'); ?>">
            <th class="check-column" scope="row">
              <input type="checkbox" name="controllers[]" value="<?php echo $controller; ?>" />
            </th>
            <td class="plugin-title">
              <strong><?php echo $info['name']; ?></strong>
              <div class="row-actions-visible">
                <?php

                if ($active) {
                  echo '<a href="' . wp_nonce_url('options-general.php?page=json-api&amp;action=deactivate&amp;controller=' . $controller, 'update-options') . '" title="' . __('Deactivate this controller') . '" class="edit">' . __('Deactivate') . '</a>';
                } else if (!$error) {
                  echo '<a href="' . wp_nonce_url('options-general.php?page=json-api&amp;action=activate&amp;controller=' . $controller, 'update-options') . '" title="' . __('Activate this controller') . '" class="edit">' . __('Activate') . '</a>';
                }

                if (!empty($info['url'])) {
                  echo ' | ';
                  echo '<a href="' . $info['url'] . '" target="_blank">Docs</a></div>';
                }

                ?>
            </td>
            <td class="desc">
              <p><?php echo $info['description']; ?></p>
              <p>
                <?php

                foreach($info['methods'] as $method) {
                  $url = $this->get_method_url($controller, $method);
                  if ($active) {
                    echo "<code><a href=\"$url\">$method</a></code> ";
                  } else {
                    echo "<code>$method</code> ";
                  }
                }

                ?>
              </p>
            </td>
          </tr>
        <?php } ?>
      </tbody>
    </table>
    <?php $this->print_controller_actions('action2'); ?>
    <h3>Address</h3>
    <p>Specify a base URL for JSON API. For example, using <code>api</code> as your API base URL would enable the following <code><?php bloginfo('url'); ?>/api/get_recent_posts/</code>. If you assign a blank value the API will only be available by setting a <code>json</code> query variable.</p>
    <table class="form-table">
      <tr valign="top">
        <th scope="row">API base</th>
        <td><code><?php bloginfo('url'); ?>/</code><input type="text" name="json_api_base" value="<?php echo get_option('json_api_base', 'api'); ?>" size="15" /></td>
      </tr>
    </table>
    <?php if (!get_option('permalink_structure', '')) { ?>
      <br />
      <p><strong>Note:</strong> User-friendly permalinks are not currently enabled. <a target="_blank" class="button" href="options-permalink.php">Change Permalinks</a>
    <?php } ?>
    <h3>Global caching</h3>
    <p>JSON API can remember all requests it has received and the corresponding responses sent for a specified amount of time. If an identical comes in during that time, the remembered response is sent, avoiding having to fully process the request. This provides a speed boost when serving multiple similar requests within short periods of time, but can also cause issues by sending outdated responses.</p>
    <p>Should not be used if any of the methods of any of the controllers enabled above allow modifying the database.</p>
    <table class="form-table">
      <tr valign="top">
        <th scope="row">Caching time</th>
        <td><input type="text" name="json_api_cache_duration" value="<?php echo get_option('json_api_cache_duration', 0); ?>" size="4" /> seconds</td>
      </tr>
    </table>
    <h3>Benchmarking</h3>
    <p>When benchmarking is enabled, the time spent is displayed in the end of every JSON response. Only for testing purposes.</p>
    <table class="form-table">
      <tr valign="top">
        <th scope="row">Enable benchmarking</th>
        <td><input type="checkbox" name="json_api_benchmarking" <?php echo ( get_option('json_api_benchmarking') ? 'checked="checked" ' : '' ); ?>/></td>
      </tr>
    </table>
    <p class="submit">
      <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
    </p>
  </form>
</div>
<?php
  }

  function print_controller_actions($name = 'action') {
    ?>
    <div class="tablenav">
      <div class="alignleft actions">
        <select name="<?php echo $name; ?>">
          <option selected="selected" value="-1">Bulk Actions</option>
          <option value="activate">Activate</option>
          <option value="deactivate">Deactivate</option>
        </select>
        <input type="submit" class="button-secondary action" id="doaction" name="doaction" value="Apply">
      </div>
      <div class="clear"></div>
    </div>
    <div class="clear"></div>
    <?php
  }

  function get_method_url($controller, $method, $options = '') {
    $url = get_bloginfo('url');
    $base = get_option('json_api_base', 'api');
    $permalink_structure = get_option('permalink_structure', '');
    if (!empty($options) && is_array($options)) {
      $args = array();
      foreach ($options as $key => $value) {
        $args[] = urlencode($key) . '=' . urlencode($value);
      }
      $args = implode('&', $args);
    } else {
      $args = $options;
    }
    if ($controller != 'core') {
      $method = "$controller/$method";
    }
    if (!empty($base) && !empty($permalink_structure)) {
      if (!empty($args)) {
        $args = "?$args";
      }
      return "$url/$base/$method/$args";
    } else {
      return "$url?json=$method&$args";
    }
  }

  function save_option($id, $value) {
    $option_exists = (get_option($id, null) !== null);
    if ($option_exists) {
      update_option($id, $value);
    } else {
      add_option($id, $value);
    }
  }

  function get_controllers() {
    $controllers = array();
    $dir = json_api_dir();
    $this->check_directory_for_controllers("$dir/controllers", $controllers);
    $this->check_directory_for_controllers(get_stylesheet_directory(), $controllers);
    $controllers = apply_filters('json_api_controllers', $controllers);
    return array_map('strtolower', $controllers);
  }

  function check_directory_for_controllers($dir, &$controllers) {
    $dh = opendir($dir);
    while ($file = readdir($dh)) {
      if (preg_match('/(.+)\.php$/i', $file, $matches)) {
        $src = file_get_contents("$dir/$file");
        if (preg_match("/class\s+JSON_API_{$matches[1]}_Controller/i", $src)) {
          $controllers[] = $matches[1];
        }
      }
    }
  }

  function controller_is_active($controller) {
    if (defined('JSON_API_CONTROLLERS')) {
      $default = JSON_API_CONTROLLERS;
    } else {
      $default = 'core';
    }
    $active_controllers = explode(',', get_option('json_api_controllers', $default));
    return (in_array($controller, $active_controllers));
  }

  function update_controllers($controllers) {
    if (is_array($controllers)) {
      return implode(',', $controllers);
    } else {
      return $controllers;
    }
  }

  function controller_info($controller) {
    $path = $this->controller_path($controller);
    $class = $this->controller_class($controller);
    $response = array(
      'name' => $controller,
      'description' => '(No description available)',
      'methods' => array()
    );
    if (file_exists($path)) {
      $source = file_get_contents($path);
      if (preg_match('/^\s*Controller name:(.+)$/im', $source, $matches)) {
        $response['name'] = trim($matches[1]);
      }
      if (preg_match('/^\s*Controller description:(.+)$/im', $source, $matches)) {
        $response['description'] = trim($matches[1]);
      }
      if (preg_match('/^\s*Controller URI:(.+)$/im', $source, $matches)) {
        $response['docs'] = trim($matches[1]);
      }
      if (!class_exists($class)) {
        require_once($path);
      }
      $response['methods'] = get_class_methods($class);
      return $response;
    } else if (is_admin()) {
      return "Cannot find controller class '$class' (filtered path: $path).";
    } else {
      $this->error("Unknown controller '$controller'.");
    }
    return $response;
  }

  function controller_class($controller) {
    return "json_api_{$controller}_controller";
  }

  function controller_path($controller) {
    $json_api_dir = json_api_dir();
    $json_api_path = "$json_api_dir/controllers/$controller.php";
    $theme_dir = get_stylesheet_directory();
    $theme_path = "$theme_dir/$controller.php";
    if (file_exists($theme_path)) {
      $path = $theme_path;
    } else if (file_exists($json_api_path)) {
      $path = $json_api_path;
    } else {
      $path = null;
    }
    $controller_class = $this->controller_class($controller);
    return apply_filters("{$controller_class}_path", $path);
  }

  function get_nonce_id($controller, $method) {
    $controller = strtolower($controller);
    $method = strtolower($method);
    return "json_api-$controller-$method";
  }

  function flush_rewrite_rules() {
    global $wp_rewrite;
    $wp_rewrite->flush_rules();
  }

  function error($message = 'Unknown error', $status = 'error') {
    $this->response->respond(array(
      'error' => $message
    ), $status);
  }

  function include_value($key) {
    return $this->response->is_value_included($key);
  }

}

?>
