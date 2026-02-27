<?php
/**
 * Plugin Name: Sail Results Parser
 * Description: Paste HTML sailing results (WYSIWYG), fetch TackTracker race/course/marks via API, save imports (update by raceid), show race index + race view, and compute SCT/Derived PY/GL excluding outliers.
 * Version: 0.24.7
 * Author: Simon
 */

if (!defined('ABSPATH')) exit;

define('SRP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SRP_DB_VERSION', '0.2');

require_once SRP_PLUGIN_DIR . 'includes/parser.php';
require_once SRP_PLUGIN_DIR . 'includes/tt-api.php';
require_once SRP_PLUGIN_DIR . 'includes/stats.php';
require_once SRP_PLUGIN_DIR . 'includes/xlsx-reader.php';

function srp_db_migrate() {
  global $wpdb;
  $table = $wpdb->prefix . 'srp_class_stats';
  $charset = $wpdb->get_charset_collate();
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  $sql = "CREATE TABLE $table (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_name VARCHAR(191) NOT NULL,
    derived_py DOUBLE NULL,
    derived_gl DOUBLE NULL,
    sct_avg_lap_s DOUBLE NULL,
    sample_n INT NULL,
    outlier_k DOUBLE NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY class_name (class_name)
  ) $charset;";

  dbDelta($sql);
  update_option('srp_db_version', SRP_DB_VERSION);
}


// -----------------------------------------------------------------------------
// RYA Bulk Import
// -----------------------------------------------------------------------------

/**
 * Upload an XLSX export where each row is a result line.
 * Creates one srp_import post per race, fetches TT race/course/marks, and optionally processes.
 */

function srp_rya_bulk_import_page() {
  if (!current_user_can('edit_posts')) return;

  echo '<div class="wrap"><h1>RYA Bulk Import</h1>';
  echo '<p>Upload an XLSX file (first sheet) where each row is a result record. This importer supports <strong>date-only</strong> exports. It will help you match each race group to the correct TackTracker <code>raceid</code> before importing.</p>';

  $user_key = 'srp_rya_pending_' . get_current_user_id();
  $pending = get_transient($user_key);

  // Step 2: confirm mappings
  if (!empty($_POST['srp_rya_confirm_import']) && check_admin_referer('srp_rya_confirm_import')) {
    $pending = get_transient($user_key);
    $created_links = [];
    if (empty($pending) || empty($pending['groups'])) {
      echo '<div class="notice notice-error"><p>No pending import found. Please upload the XLSX again.</p></div>';
    } else {
      $mapping = $_POST['srp_tt_match'] ?? [];
      $process_now = !empty($_POST['srp_rya_process_now']);
      $created = 0;
      $errors = [];

      foreach ($pending['groups'] as $gkey => $g) {
        $tt_raceid = isset($mapping[$gkey]) ? sanitize_text_field((string)$mapping[$gkey]) : '';
        // Allow manual override (useful if TT API list-by-date is incomplete or you already know the raceid).
        $manual = $_POST['srp_tt_manual'][$gkey] ?? '';
        $manual = trim(sanitize_text_field((string)$manual));
        if ($manual !== '') {
          // raceid is numeric in TT; keep digits only.
          $manual_digits = preg_replace('/[^0-9]/', '', $manual);
          if ($manual_digits !== '') $tt_raceid = $manual_digits;
        }
        if ($tt_raceid === '') {
          $errors[] = 'Skipped group ' . esc_html($gkey) . ' (no TT race selected).';
          continue;
        }

        $post_id = wp_insert_post([
          'post_type'   => 'srp_import',
          'post_status' => 'private',
          'post_title'  => 'RYA Import ' . $tt_raceid,
        ]);
        if (is_wp_error($post_id) || !$post_id) {
          $errors[] = 'Failed to create import post for TT race ' . $tt_raceid;
          continue;
        }

        $grows = $g['rows'] ?? [];
        update_post_meta($post_id, 'srp_race_id', (string)$tt_raceid);
        update_post_meta($post_id, 'srp_source', 'rya_xlsx');
        update_post_meta($post_id, 'srp_rya_race_id', (string)($g['rya_race_id'] ?? ''));
        update_post_meta($post_id, 'srp_rya_start_id', (string)($g['rya_start_id'] ?? ''));
        update_post_meta($post_id, 'srp_rya_date', (string)($g['date'] ?? ''));
        update_post_meta($post_id, 'srp_parsed_rows', wp_json_encode($grows));

        // Fetch TT Race/Course/Marks (normal flow)
        $race = srp_tt_fetch_race($tt_raceid);
        if ($race) update_post_meta($post_id, 'srp_tt_race', wp_json_encode($race));
        $course = srp_tt_fetch_course($tt_raceid);
        if ($course) update_post_meta($post_id, 'srp_tt_course', wp_json_encode($course));
        $marks = srp_tt_fetch_marks($tt_raceid);
        if ($marks) update_post_meta($post_id, 'srp_tt_marks', wp_json_encode($marks));

        if ($process_now) {
          $k = floatval(get_option('srp_outlier_k', 2.0));
          $computed = srp_compute_dual($grows, $k);
          update_post_meta($post_id, 'srp_rows_with_stats', wp_json_encode($computed['rows'] ?? $grows));
          update_post_meta($post_id, 'srp_class_stats', wp_json_encode($computed['class_stats'] ?? []));
          update_post_meta($post_id, 'srp_debug', wp_json_encode($computed['debug'] ?? []));
        }

        $created_links[] = ['post_id' => $post_id, 'raceid' => $tt_raceid];
        $created++;
      }

      if (!empty($created_links)) {
      echo '<div class="notice notice-success"><p>Imported ' . count($created_links) . ' race(s).</p></div>';
      echo '<ul style="margin-left:20px;">';
      foreach ($created_links as $cl) {
        $url = admin_url('admin.php?page=srp-race-view&post_id=' . intval($cl['post_id']));
        echo '<li><a href="' . esc_url($url) . '">TT raceid ' . esc_html($cl['raceid']) . ' → import #' . intval($cl['post_id']) . '</a></li>';
      }
      echo '</ul>';
      echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=srp-race-index')) . '">Go to Race Index</a></p>';
    }

    delete_transient($user_key);

      echo '<div class="notice notice-success"><p>Created ' . intval($created) . ' import(s).</p></div>';
      if (!empty($errors)) {
        echo '<div class="notice notice-warning"><p><strong>Notes:</strong></p><ul style="margin-left:1.5em;list-style:disc;">';
        foreach ($errors as $e) echo '<li>' . esc_html($e) . '</li>';
        echo '</ul></div>';
      }
      echo '</div>'; // wrap
      return;
    }
  }

  // Step 1: upload and build pending groups
  if (!empty($_POST['srp_rya_bulk_import']) && check_admin_referer('srp_rya_bulk_import')) {
    if (empty($_FILES['srp_rya_file']) || empty($_FILES['srp_rya_file']['tmp_name'])) {
      echo '<div class="notice notice-error"><p>No file uploaded.</p></div>';
    } else {
      $rows = srp_xlsx_read_rows($_FILES['srp_rya_file']['tmp_name']);
      if (empty($rows)) {
        echo '<div class="notice notice-error"><p>Could not read XLSX (or it contains no rows).</p></div>';
      } else {
        $groups = [];
        foreach ($rows as $r) {
          // RYA identifiers
          $rya_race_id = '';
          foreach (['race_id','raceid','Race ID','RaceID'] as $k) {
            if (!empty($r[$k])) { $rya_race_id = trim((string)$r[$k]); break; }
          }
          $rya_start_id = '';
          foreach (['start_id','Start ID','StartID','startid'] as $k) {
            if (!empty($r[$k])) { $rya_start_id = trim((string)$r[$k]); break; }
          }

          $date = srp_rya_guess_date($r);
          if (!$date) $date = 'unknown';

          $gkey = $date . '|' . ($rya_race_id !== '' ? $rya_race_id : 'race?') . '|' . ($rya_start_id !== '' ? $rya_start_id : 'start?');
          if (!isset($groups[$gkey])) {
            $groups[$gkey] = [
              'date' => $date,
              'rya_race_id' => $rya_race_id,
              'rya_start_id' => $rya_start_id,
              'rows' => [],
            ];
          }
          $groups[$gkey]['rows'][] = $r;
        }

	  	  // Preload TT candidates per date (date-only exports).
	  	  // TackTracker list-by-date expects a UTC datetime; use midday to sit safely inside the date.
	  	  $tt_candidates = [];
	  	  foreach ($groups as $g) {
	  	    if (empty($g['date']) || $g['date'] === 'unknown') continue;
	  	    $ymd = is_string($g['date']) ? $g['date'] : '';
	  	    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $ymd)) continue;
	  	    if (!isset($tt_candidates[$ymd])) {
	  	    $tt_candidates[$ymd] = srp_tt_list_races_by_date($ymd);
	  	    }
	  	  }

        $pending = [
          'created_at' => time(),
          'groups' => $groups,
          'tt_candidates' => $tt_candidates,
        ];
        set_transient($user_key, $pending, 60 * 60 * 6); // 6 hours
        echo '<div class="notice notice-info"><p>File parsed. Now match each race group to a TackTracker race.</p></div>';
      }
    }
  }

  // If we have pending groups, show matching UI
  $pending = get_transient($user_key);
  if (!empty($pending) && !empty($pending['groups'])) {
    echo '<h2>Step 2 — Match each group to a TackTracker race</h2>';
    echo '<form method="post">';
    wp_nonce_field('srp_rya_confirm_import');
    echo '<table class="widefat striped" style="max-width: 1100px;">';
    echo '<thead><tr><th>RYA Date</th><th>RYA race_id</th><th>RYA start_id</th><th>Rows</th><th>Match TT race</th></tr></thead><tbody>';

    foreach ($pending['groups'] as $gkey => $g) {
      $date = $g['date'] ?? 'unknown';
      $opts = $pending['tt_candidates'][$date] ?? [];
      echo '<tr>';
      echo '<td>' . esc_html($date) . '</td>';
      echo '<td>' . esc_html($g['rya_race_id'] ?? '') . '</td>';
      echo '<td>' . esc_html($g['rya_start_id'] ?? '') . '</td>';
      echo '<td>' . intval(count($g['rows'] ?? [])) . '</td>';

      echo '<td>';
      echo '<select name="srp_tt_match[' . esc_attr($gkey) . ']" style="min-width: 420px;">';
      echo '<option value="">— Select TT race —</option>';
      foreach ($opts as $r) {
        $rid = $r['raceid'] ?? '';
        $label = $rid;
        $nm = trim((string)($r['name'] ?? ''));
        $st = trim((string)($r['start'] ?? ''));
        if ($nm !== '' || $st !== '') $label .= ' — ' . ($nm !== '' ? $nm : '(no name)') . ($st !== '' ? ' @ ' . $st : '');
        echo '<option value="' . esc_attr($rid) . '">' . esc_html($label) . '</option>';
      }
      echo '</select>';

      if (empty($opts)) {
        echo '<div style="margin-top:6px;color:#b45309">No TT races returned for this date. You can enter a TT <code>raceid</code> manually.</div>';
        if ($dbg) {
          echo '<details style="margin-top:6px;"><summary>Debug TackTracker lookup</summary><pre style="white-space:pre-wrap;">' . esc_html(print_r($dbg, true)) . '</pre></details>';
        }
      }

      echo '<div style="margin-top:6px">'
        . '<label style="display:block;font-size:12px;color:#555">Manual TT raceid (optional)</label>'
        . '<input type="text" name="srp_tt_manual[' . esc_attr($gkey) . ']" value="" placeholder="e.g. 1810098250" style="max-width:220px" />'
        . '</div>';

      echo '<div style="margin-top:6px;color:#666;">(If you have many races on a date, use the TT race start time/name above to pick the right one.)</div>';
      echo '</td>';

      echo '</tr>';
    }

    echo '</tbody></table>';

    echo '<p style="margin-top:12px;"><label><input type="checkbox" name="srp_rya_process_now" value="1" checked> Process races immediately (compute SCT/Derived/Class Stats)</label></p>';
    echo '<p><button class="button button-primary" name="srp_rya_confirm_import" value="1">Create Imports</button> ';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=srp-rya-bulk-import')) . '">Upload a different file</a></p>';
    echo '</form>';

    echo '</div>';
    return;
  }

  // Default: show upload form
  echo '<h2>Step 1 — Upload XLSX</h2>';
  echo '<form method="post" enctype="multipart/form-data">';
  wp_nonce_field('srp_rya_bulk_import');
  echo '<table class="form-table" role="presentation"><tbody>';
  echo '<tr><th scope="row"><label for="srp_rya_file">XLSX file</label></th><td><input type="file" id="srp_rya_file" name="srp_rya_file" accept=".xlsx" required></td></tr>';
  echo '</tbody></table>';
  echo '<p><button class="button button-primary" name="srp_rya_bulk_import" value="1">Parse XLSX</button></p>';
  echo '</form>';

  echo '</div>';
}


function srp_rya_guess_start_datetime(array $row): ?int {
  foreach (['start_time','Start time','Start Time','Start'] as $k) {
    if (!empty($row[$k])) {
      $t = trim((string)$row[$k]);
      $ts = strtotime($t . ' UTC');
      if ($ts) return $ts;
    }
  }
  return null;
}

function srp_rya_guess_date(array $row): ?string {
  // Prefer explicit date columns (often date-only in RYA exports)
  $date = '';
  foreach (['date','Date','race_date','Race Date','RaceDate'] as $k) {
    if (!empty($row[$k])) { $date = trim((string)$row[$k]); break; }
  }

  // Handle Excel serial dates (common in XLSX): days since 1899-12-30
  if ($date !== '' && preg_match('/^\d+(?:\.\d+)?$/', $date)) {
    $n = floatval($date);
    if ($n > 30000 && $n < 60000) {
      $ts = (int)(($n - 25569) * 86400); // Excel -> Unix
      return gmdate('Y-m-d', $ts);
    }
  }

  if ($date !== '') {
    $ts = strtotime($date . ' UTC');
    if ($ts) return gmdate('Y-m-d', $ts);
  }

  // Fall back: try parse start_time and take the date part
  foreach (['start_time','Start time','Start Time','Start'] as $k) {
    if (!empty($row[$k])) {
      $t = trim((string)$row[$k]);
      $ts = strtotime($t . ' UTC');
      if ($ts) return gmdate('Y-m-d', $ts);
    }
  }

  return null;
}


register_activation_hook(__FILE__, 'srp_db_migrate');
add_action('init', function() {
  $v = get_option('srp_db_version', '');
  if ($v !== SRP_DB_VERSION) srp_db_migrate();
});

function srp_enqueue_leaflet() {
  wp_register_style('srp-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
  wp_register_script('srp-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
  wp_enqueue_style('srp-leaflet');
  wp_enqueue_script('srp-leaflet');
}


/**
 * Try to extract a course/lap length from the stored course payload.
 */
function srp_format_course_length($course) {
  if (!is_array($course)) return '';
  $candidates = [
    ['lapLengthMeters','m'],
    ['lapLengthMetres','m'],
    ['lapLength','m'],
    ['lapLength_m','m'],
    ['lapLengthKm','km'],
    ['lapLengthNM','nm'],
    ['courseLengthMeters','m'],
    ['courseLengthMetres','m'],
    ['courseLength','m'],
  ];
  foreach ($candidates as $cand) {
    [$k,$unit] = $cand;
    if (isset($course[$k]) && is_numeric($course[$k])) {
      $v = floatval($course[$k]);
      if ($unit === 'm') return number_format_i18n($v, 1) . ' m';
      if ($unit === 'km') return number_format_i18n($v, 3) . ' km';
      if ($unit === 'nm') return number_format_i18n($v, 3) . ' nm';
      return number_format_i18n($v, 2) . ' ' . $unit;
    }
  }
  // sometimes nested
  foreach (['settings','course','data'] as $nest) {
    if (isset($course[$nest]) && is_array($course[$nest])) {
      $x = srp_format_course_length($course[$nest]);
      if ($x) return $x;
    }
  }
  return '';
}

function srp_haversine_m($lat1,$lon1,$lat2,$lon2){
  $R = 6371000.0;
  $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
  $dphi = deg2rad($lat2-$lat1);
  $dl = deg2rad($lon2-$lon1);
  $a = sin($dphi/2)*sin($dphi/2) + cos($phi1)*cos($phi2)*sin($dl/2)*sin($dl/2);
  $c = 2*atan2(sqrt($a), sqrt(1-$a));
  return $R*$c;
}
function srp_course_length_from_marks($course_string, $marks, $finish_at_start=false) {
  if (!$course_string || !is_array($marks) || empty($marks)) return null;
  $lookup = [];
  foreach ($marks as $name => $loc) {
    if (is_array($loc) && isset($loc['lat']) && isset($loc['long'])) {
      $lookup[strtolower(trim((string)$name))] = ['lat'=>(float)$loc['lat'],'lng'=>(float)$loc['long']];
    }
  }
  if (empty($lookup)) return null;

  $s = preg_replace('/[>,;]/', ' ', (string)$course_string);
  $tokens = preg_split('/\s+/', trim($s));
  $points = [];
  foreach ($tokens as $t) {
    $t = trim($t);
    if ($t === '') continue;
    $t = preg_replace('/[\(\)\[\]\{\}]/', '', $t);
    $key = strtolower($t);
    if (isset($lookup[$key])) $points[] = $lookup[$key];
  }
  if (count($points) < 2) return null;

  $dist = 0.0;
  for ($i=1; $i<count($points); $i++) {
    $dist += srp_haversine_m($points[$i-1]['lat'],$points[$i-1]['lng'],$points[$i]['lat'],$points[$i]['lng']);
  }

  // Always treat course as a loop for lap length: include last->first if it isn't already.
  $last = $points[count($points)-1];
  $first = $points[0];
  if ($last['lat'] !== $first['lat'] || $last['lng'] !== $first['lng']) {
    $dist += srp_haversine_m($last['lat'],$last['lng'],$first['lat'],$first['lng']);
  }

  // If finish_at_start is explicitly set, this is already included above; keep arg for backward compat.
  return $dist;
}

function srp_enqueue_datatables() {
  wp_register_style('srp-dt', 'https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css', [], '1.13.8');
  wp_register_script('srp-dt', 'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js', ['jquery'], '1.13.8', true);
  // FixedHeader extension (for the imported results table header)
  wp_register_style('srp-dt-fixedheader', 'https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css', ['srp-dt'], '3.4.0');
  wp_register_script('srp-dt-fixedheader', 'https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js', ['srp-dt'], '3.4.0', true);
  wp_enqueue_style('srp-dt');
  wp_enqueue_style('srp-dt-fixedheader');
  wp_enqueue_script('srp-dt');
  wp_enqueue_script('srp-dt-fixedheader');
}

add_action('admin_enqueue_scripts', function() {
  $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
  $post_type = isset($_GET['post_type']) ? sanitize_text_field(wp_unslash($_GET['post_type'])) : '';
  $pt = isset($_GET['post']) ? get_post_type((int)$_GET['post']) : '';

  if ($page === 'sail-results-parser' || $post_type === 'srp_import' || $pt === 'srp_import' || $page === 'srp-race-view') srp_enqueue_leaflet();
  if (in_array($page, ['srp-race-index','srp-class-stats','srp-race-view'], true)) srp_enqueue_datatables();
});

add_action('init', function () {
  register_post_type('srp_import', [
    'labels' => ['name' => 'Sailing Imports','singular_name' => 'Sailing Import'],
    'public' => false,
    'show_ui' => true,
    'show_in_menu' => 'sail-results-parser',
    'supports' => ['title'],
    'capability_type' => 'post',
    'map_meta_cap' => true,
  ]);
});

add_action('admin_menu', function () {
  add_menu_page('Sailing Results Import','Sailing Results','manage_options','sail-results-parser','srp_admin_page','dashicons-list-view',56);
  add_submenu_page('sail-results-parser','Race Index','Race Index','manage_options','srp-race-index','srp_race_index_page');
  add_submenu_page('sail-results-parser','Class Stats','Class Stats','manage_options','srp-class-stats','srp_class_stats_page');
  add_submenu_page('sail-results-parser','RYA Bulk Import','RYA Bulk Import','manage_options','srp-rya-bulk-import','srp_rya_bulk_import_page');
  add_submenu_page(null,'Race View','Race View','manage_options','srp-race-view','srp_race_view_page');
  add_submenu_page('sail-results-parser','Sailing Imports','Sailing Imports','manage_options','edit.php?post_type=srp_import');
  add_submenu_page('sail-results-parser','Settings','Settings','manage_options','srp-settings','srp_settings_page');
  add_submenu_page('sail-results-parser','Data QA','Data QA','manage_options','srp-data-qa','srp_data_qa_page');
});

add_action('admin_init', function () {
  register_setting('srp_settings', 'srp_tt_api_key', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
  register_setting('srp_settings', 'srp_outlier_k', ['type' => 'number', 'sanitize_callback' => 'floatval', 'default' => 2.0]);
  // Maps are enabled by default; can be disabled if performance is an issue.
  register_setting('srp_settings', 'srp_enable_map', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '1']);
});

// AJAX handlers (admin)
add_action('wp_ajax_srp_save_row_edit', 'srp_ajax_save_row_edit');

function srp_settings_page() {
  if (!current_user_can('edit_posts')) return;
  echo '<div class="wrap"><h1>Sail Results Parser Settings</h1><form method="post" action="options.php">';
  settings_fields('srp_settings');
  $api_key = get_option('srp_tt_api_key', '');
  $k = get_option('srp_outlier_k', 2.0);
  $enable_map = (get_option('srp_enable_map', '1') === '1');
  echo '<table class="form-table" role="presentation"><tbody>';
  echo '<tr><th scope="row"><label for="srp_tt_api_key">TackTracker API Key</label></th><td>';
  echo '<input type="text" id="srp_tt_api_key" name="srp_tt_api_key" value="' . esc_attr($api_key) . '" class="regular-text" />';
  echo '<p class="description">Sent as HTTP header <code>X-API-KEY</code> to <code>https://tacktracker.com/api/</code>.</p>';
  echo '</td></tr>';
  echo '<tr><th scope="row"><label for="srp_outlier_k">Outlier removal (std dev multiplier)</label></th><td>';
  echo '<input type="number" step="0.1" min="0" id="srp_outlier_k" name="srp_outlier_k" value="' . esc_attr($k) . '" class="small-text" />';
  echo '<p class="description">Rows outside mean ± (k × std dev) are excluded when computing SCT (per class).</p>';
  echo '</td></tr>';
  echo '<tr><th scope="row">Map rendering</th><td>';
  echo '<label><input type="checkbox" name="srp_enable_map" value="1" ' . checked(true, $enable_map, false) . ' /> Show Leaflet course maps on race view</label>';
  echo '<p class="description">Disable this if the admin race view feels slow. You can still view results and stats.</p>';
  echo '</td></tr>';
  echo '</tbody></table>';
  submit_button('Save Settings');
  echo '</form></div>';
}

add_shortcode('sail_results_import', function($atts) {
  $atts = shortcode_atts(['cap' => 'manage_options','show_debug' => '0','show_map' => '1'], $atts, 'sail_results_import');
  $cap = (string)$atts['cap'];
  $show_debug = ($atts['show_debug'] === '1' || strtolower((string)$atts['show_debug']) === 'true');
  $show_map = !($atts['show_map'] === '0' || strtolower((string)$atts['show_map']) === 'false');

  if (!is_user_logged_in()) return '<p><em>You must be logged in to import results.</em></p>';
  if ($cap && !current_user_can($cap)) return '<p><em>You do not have permission to import results.</em></p>';

  if (function_exists('wp_enqueue_editor')) wp_enqueue_editor();
  if ($show_map) { add_action('wp_enqueue_scripts','srp_enqueue_leaflet'); srp_enqueue_leaflet(); }

  ob_start();
  srp_render_import_ui(['context'=>'shortcode','show_debug'=>$show_debug,'show_map'=>$show_map]);
  return ob_get_clean();
});

function srp_admin_page() {
  if (!current_user_can('edit_posts')) return;
  echo '<div class="wrap"><h1>Sailing Results Import</h1>';
  echo '<p>Save will <strong>update</strong> the existing record if the TT raceid already exists.</p>';
  srp_render_import_ui(['context'=>'admin','show_debug'=>true,'show_map'=>true]);
  echo '</div>';
}

function srp_race_index_page() {
  if (!current_user_can('edit_posts')) return;
  $imports = get_posts(['post_type'=>'srp_import','post_status'=>['private'],'numberposts'=>2000,'orderby'=>'modified','order'=>'DESC']);
  echo '<div class="wrap"><h1>Race Index</h1><p>Saved imports list (search + sort). Click a race to open it.</p><style>#srp_race_index thead th{position:sticky;top:32px;background:#fff;z-index:5;}#srp_race_index{border-collapse:separate;}</style><div id="srp_race_index_filters" style="margin:12px 0; display:flex; gap:12px; align-items:center; flex-wrap:wrap;"><label>Start from <input type="date" id="srp_start_from" /></label><label>to <input type="date" id="srp_start_to" /></label><button type="button" class="button" id="srp_clear_dates">Clear</button></div>';
  echo '<table id="srp_race_index" class="widefat striped"><thead><tr><th>Race ID</th><th>Race Name</th><th>Start</th><th>Updated</th><th>Has Course</th><th>Has Marks</th><th>Rows</th><th>Actions</th></tr></thead><tbody>';
  foreach($imports as $p){
    $raceid=get_post_meta($p->ID,'srp_raceid',true);
    $racename=get_post_meta($p->ID,'srp_race_name',true);
    $race_details=json_decode((string)get_post_meta($p->ID,'srp_tt_race_details',true),true);
    $start=(is_array($race_details)&&!empty($race_details['startDateTime']))?$race_details['startDateTime']:'';
    $course=get_post_meta($p->ID,'srp_tt_course',true);
    $marks=get_post_meta($p->ID,'srp_tt_marks',true);
    $rows=json_decode((string)get_post_meta($p->ID,'srp_parsed_rows',true),true);
    $rows_n=is_array($rows)?count($rows):0;
    $has_course=($course&&$course!=='null')?'Yes':'No';
    $has_marks=($marks&&$marks!=='null')?'Yes':'No';
    $view=admin_url('admin.php?page=srp-race-view&post_id='.intval($p->ID));
        $del = wp_nonce_url(admin_url('admin-post.php?action=srp_delete_import&post_id='.intval($p->ID)), 'srp_delete_import_'.$p->ID);
    $ovr = wp_nonce_url(admin_url('admin-post.php?action=srp_overwrite_import&post_id='.intval($p->ID)), 'srp_overwrite_import_'.$p->ID);
    $actions = '<a class="button button-small" href="'.esc_url($view).'">View</a> ';
    $actions .= '<a class="button button-small" href="'.esc_url($ovr).'">Overwrite</a> ';
    $actions .= '<a class="button button-small" style="color:#b32d2e;border-color:#b32d2e;" href="'.esc_url($del).'" onclick="return confirm(\'Delete this race import?\');">Delete</a>';
    echo '<tr><td><a href="'.esc_url($view).'">'.esc_html($raceid).'</a></td><td>'.esc_html($racename).'</td><td>'.esc_html($start).'</td><td>'.esc_html(get_the_modified_date('Y-m-d H:i',$p)).'</td><td>'.esc_html($has_course).'</td><td>'.esc_html($has_marks).'</td><td>'.esc_html($rows_n).'</td><td>'.$actions.'</td></tr>';
  }
  echo '</tbody></table><script>jQuery(function($){
  if(!$.fn.DataTable) return;
  // Date range filter on Start column (index 2).
  $.fn.dataTable.ext.search.push(function(settings, data){
    if(settings.nTable && settings.nTable.id !== "srp_race_index") return true;
    var min = $("#srp_start_from").val();
    var max = $("#srp_start_to").val();
    var start = (data[2] || "").toString();
    var d = start.length >= 10 ? start.substring(0,10) : "";
    if(!min && !max) return true;
    if(!d) return false;
    if(min && d < min) return false;
    if(max && d > max) return false;
    return true;
  });
  var dt = $("#srp_race_index").DataTable({
    pageLength: 100,
    lengthMenu: [[25,50,100,200,-1],[25,50,100,200,"All"]],
    order: [[2,"desc"]],
    autoWidth: false,
    fixedHeader: true
  });
  function redraw(){ dt.draw(false); dt.columns.adjust(); }
  $("#srp_start_from,#srp_start_to").on("change", redraw);
  $("#srp_clear_dates").on("click", function(){ $("#srp_start_from").val(""); $("#srp_start_to").val(""); redraw(); });
  // Adjust headers after initial render.
  setTimeout(function(){ dt.columns.adjust(); }, 0);
  $(window).on("resize", function(){ dt.columns.adjust(); });
});</script></div>';
}






function srp_class_stats_page() {
  if (!current_user_can('edit_posts')) return;

  // Filters
  $filter_class = isset($_GET['srp_class']) ? sanitize_text_field((string)$_GET['srp_class']) : '';
  $start_date = isset($_GET['srp_start']) ? sanitize_text_field((string)$_GET['srp_start']) : '';
  $end_date   = isset($_GET['srp_end']) ? sanitize_text_field((string)$_GET['srp_end']) : '';
  $include_linked = isset($_GET['srp_linked']) ? sanitize_text_field((string)$_GET['srp_linked']) : 'No';

  $start_ts = $start_date ? strtotime($start_date . ' 00:00:00') : null;
  $end_ts   = $end_date   ? strtotime($end_date   . ' 23:59:59') : null;

  // Aggregate weighted average derived PY from saved races meta, with optional date filter
  $agg = [];     // class => stats for derived
  $latest = [];  // class => ['ts'=>, 'actual_py'=>]
  $all_classes = [];

  $race_posts = get_posts([
    'post_type' => 'srp_import',
    'post_status' => ['publish','private','draft'],
    'numberposts' => -1,
    'fields' => 'ids',
  ]);

  foreach ($race_posts as $pid) {
    $details = json_decode((string)get_post_meta($pid, 'srp_tt_race_details', true), true);
    $race_ts = null;
    if (is_array($details)) {
      foreach ($details as $k => $v) {
        $lk = strtolower((string)$k);
        if (in_array($lk, ['start','starttime','start_time','startdatetime','start_date','racestart'], true) || strpos($lk,'start') !== false) {
          $race_ts = strtotime((string)$v);
          if ($race_ts) break;
        }
      }
    }
    // If we couldn't parse start, don't filter it out.
    if ($race_ts !== null) {
      if ($start_ts !== null && $race_ts < $start_ts) continue;
      if ($end_ts !== null && $race_ts > $end_ts) continue;
    }

    $cs = json_decode((string)get_post_meta($pid, 'srp_class_stats', true), true);
    if (!is_array($cs)) continue;

    // rows for "actual PY/GL used"
    $rows = json_decode((string)get_post_meta($pid, 'srp_parsed_rows', true), true);
    if (!is_array($rows)) $rows = [];

    foreach ($cs as $cls => $st) {
      if (!is_array($st)) continue;
      $class = (string)$cls;
      $all_classes[$class] = true;

      if ($filter_class !== '' && $filter_class !== $class) continue;

      $py = isset($st['derived_py']) ? floatval($st['derived_py']) : null;
      $n  = isset($st['n']) ? intval($st['n']) : 0;
      if ($py === null || $n <= 0) continue;

      if (!isset($agg[$class])) $agg[$class] = ['sum_w'=>0.0,'sum_wpy'=>0.0,'races'=>0,'appearances'=>0];
      $agg[$class]['sum_w'] += $n;
      $agg[$class]['sum_wpy'] += $py * $n;
      $agg[$class]['appearances'] += $n;
      $agg[$class]['races'] += 1;

      // update last actual PY from latest race occurrence
      if ($race_ts !== null) {
        if (!isset($latest[$class]) || $race_ts > $latest[$class]['ts']) {
          // compute median GL for this class within this race
          $gls = [];
          foreach ($rows as $r) {
            $bn = isset($r['boatname']) ? (string)$r['boatname'] : '';
            if (strcasecmp($bn, $class) !== 0) continue;
            $gl = isset($r['gl']) ? floatval($r['gl']) : (isset($r['GL']) ? floatval($r['GL']) : null);
            if ($gl !== null && $gl > 0) $gls[] = $gl;
          }
          sort($gls);
          $med = null;
          $cnt = count($gls);
          if ($cnt > 0) {
            $mid = intdiv($cnt, 2);
            $med = ($cnt % 2) ? $gls[$mid] : (($gls[$mid-1] + $gls[$mid]) / 2.0);
          }
          $latest[$class] = ['ts'=>$race_ts,'actual_py'=>$med];
        }
      }
    }
  }

  // Build rows for display
  $rows_out = [];
  foreach ($agg as $class => $a) {
    $w = $a['sum_w'];
    $avg = ($w > 0) ? ($a['sum_wpy'] / $w) : null;
    $last_actual = isset($latest[$class]) ? $latest[$class]['actual_py'] : null;

    // Simple confidence score (0..1): saturates at 50 datapoints
    $conf = min(1.0, $a['appearances'] / 50.0);

    $rows_out[] = [
      'class' => $class,
      'config' => '',
      'races' => $a['races'],
      'appearances' => $a['appearances'],
      'confidence' => $conf,
      'py' => $avg,
      'last_py' => $last_actual,
    ];
  }

  usort($rows_out, function($x,$y){
    $ax = intval($x['appearances']); $ay = intval($y['appearances']);
    if ($ax === $ay) return strcmp((string)$x['class'], (string)$y['class']);
    return ($ay <=> $ax);
  });

  // Build class dropdown options
  $class_options = array_keys($all_classes);
  sort($class_options);

  echo '<div class="wrap"><h1>Class Report</h1>';

  echo '<form method="get" style="margin:12px 0 18px 0;">';
  echo '<input type="hidden" name="page" value="srp-class-stats" />';
  echo '<div style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;">';

  echo '<div><label><strong>Class</strong><br/>';
  echo '<select name="srp_class" style="min-width:220px;">';
  echo '<option value="">- Any -</option>';
  foreach ($class_options as $c) {
    $sel = ($filter_class === $c) ? ' selected' : '';
    echo '<option value="' . esc_attr($c) . '"' . $sel . '>' . esc_html($c) . '</option>';
  }
  echo '</select></label></div>';

  echo '<div><label><strong>Start Date</strong><br/>';
  echo '<input type="date" name="srp_start" value="' . esc_attr($start_date) . '" />';
  echo '</label></div>';

  echo '<div><label><strong>End Date</strong><br/>';
  echo '<input type="date" name="srp_end" value="' . esc_attr($end_date) . '" />';
  echo '</label></div>';

  echo '<div><label><strong>Include results for linked clubs</strong><br/>';
  echo '<select name="srp_linked"><option' . ($include_linked==='No'?' selected':'') . '>No</option><option' . ($include_linked==='Yes'?' selected':'') . '>Yes</option></select>';
  echo '</label></div>';

  echo '<div><button class="button button-primary" type="submit">Apply</button></div>';
  echo '</div></form>';

  echo '<table id="srp_class_stats" class="widefat striped">';
  echo '<thead><tr>';
  echo '<th>Class</th>';
  echo '<th>Configuration</th>';
  echo '<th>Races</th>';
  echo '<th>Appearances</th>';
  echo '<th>Confidence</th>';
  echo '<th>PY</th>';
  echo '<th>Last PY</th>';
  echo '<th>Actions</th>';
  echo '</tr></thead><tbody>';

  foreach ($rows_out as $r) {
    $pdf = '#';
    $graph_url = admin_url("admin.php?page=srp-class-graph&class=" . urlencode($r['class']));
    echo '<tr>';
    echo '<td>' . esc_html($r['class']) . '</td>';
    echo '<td>' . esc_html($r['config']) . '</td>';
    echo '<td>' . esc_html(intval($r['races'])) . '</td>';
    echo '<td>' . esc_html(intval($r['appearances'])) . '</td>';
    echo '<td>' . esc_html(number_format((float)$r['confidence'], 2)) . '</td>';
    echo '<td><strong>' . esc_html($r['py'] !== null ? (int)round((float)$r['py']) : '') . '</strong></td>';
    echo '<td>' . esc_html($r['last_py'] !== null ? (int)round((float)$r['last_py']) : '') . '</td>';
    echo '<td><a href="' . esc_url($pdf) . '">PDF</a> | <a href="' . esc_url($graph_url) . '">Graph</a></td>';
    echo '</tr>';
  }

  echo '</tbody></table>';
  echo "<script>jQuery(function($){ if($.fn.DataTable){ $('#srp_class_stats').DataTable({pageLength:50, order:[[3,'desc']]}); } });</script>";
  echo '</div>';
}






function srp_race_view_page() {
  $class_stats = [];
  $calc_debug = null;
  if (!current_user_can('edit_posts')) return;
  $post_id=isset($_GET['post_id'])?intval($_GET['post_id']):0;
  if(!$post_id||get_post_type($post_id)!=='srp_import'){ echo '<div class="wrap"><h1>Race View</h1><p><em>Invalid race.</em></p></div>'; return; }

  // Analysis method toggle (all|best)
  $method = isset($_GET['method']) ? sanitize_key($_GET['method']) : 'all';
  if (!in_array($method, ['all','best'], true)) $method = 'all';


  // Save Weather (Wind/Direction/Temperature/Pressure)
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['srp_action']) && $_POST['srp_action'] === 'srp_save_weather') {
    if (!isset($_POST['srp_weather_nonce']) || !wp_verify_nonce($_POST['srp_weather_nonce'], 'srp_weather_' . $post_id)) {
      echo '<div class="notice notice-error"><p>' . esc_html('Security check failed.') . '</p></div>';
    } else {
      $wind = isset($_POST['srp_weather_wind']) ? sanitize_text_field(wp_unslash($_POST['srp_weather_wind'])) : '';
      $dir  = isset($_POST['srp_weather_dir']) ? sanitize_text_field(wp_unslash($_POST['srp_weather_dir'])) : '';
      $temp = isset($_POST['srp_weather_temp']) ? sanitize_text_field(wp_unslash($_POST['srp_weather_temp'])) : '';
      $pres = isset($_POST['srp_weather_pressure']) ? sanitize_text_field(wp_unslash($_POST['srp_weather_pressure'])) : '';

      update_post_meta($post_id, 'srp_weather_wind', $wind);
      update_post_meta($post_id, 'srp_weather_dir', $dir);
      update_post_meta($post_id, 'srp_weather_temp', $temp);
      update_post_meta($post_id, 'srp_weather_pressure', $pres);

      echo '<div class="notice notice-success"><p>' . esc_html('Saved weather fields.') . '</p></div>';
    }
  }

  // Recalculate button handler (uses saved rows; updates post meta + class stats table)
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['srp_action']) && $_POST['srp_action'] === 'srp_recalc') {
    if (!isset($_POST['srp_nonce']) || !wp_verify_nonce($_POST['srp_nonce'], 'srp_recalc_' . $post_id)) {
      echo '<div class="notice notice-error"><p>' . esc_html('Security check failed.') . '</p></div>';
    } else {
      $method = isset($_GET['method']) ? sanitize_key($_GET['method']) : 'all';
      if (!in_array($method, ['all','best'], true)) $method = 'all';
      $dual = get_post_meta($post_id, 'srp_analysis_dual', true);
      if (is_array($dual) && isset($dual[$method]['rows'])) {
        $saved_rows = $dual[$method]['rows'];
        $calc_debug = $dual[$method]['debug'] ?? null;
        $class_stats = $dual[$method]['class_stats'] ?? [];
      } else {
        $saved_rows = json_decode((string)get_post_meta($post_id, 'srp_parsed_rows', true), true);
                $calc_debug = json_decode((string)get_post_meta($post_id, 'srp_calc_debug', true), true);
      }
      if (!is_array($saved_rows) || empty($saved_rows)) {
        echo '<div class="notice notice-error"><p>' . esc_html('No saved rows to recalculate.') . '</p></div>';
      } else {
        $k = floatval(get_option('srp_outlier_k', 2.0));
        $stats_result = srp_compute_dual($saved_rows, $k);

        if (!empty($stats_result['rows'])) update_post_meta($post_id, 'srp_parsed_rows', wp_json_encode($stats_result['rows']));
        if (!empty($stats_result['class_stats'])) {
          srp_upsert_class_stats($stats_result['class_stats']);
          update_post_meta($post_id, 'srp_class_stats', wp_json_encode($stats_result['class_stats']));
        }
        if (!empty($stats_result['debug'])) update_post_meta($post_id, 'srp_stats_debug', wp_json_encode($stats_result['debug']));

        // refresh local vars (so the page shows new results immediately)
        $rows = json_decode((string)get_post_meta($post_id, 'srp_parsed_rows', true), true);
        
        echo '<div class="notice notice-success"><p>' . esc_html('Recalculated SCT / PY / GL from saved results.') . '</p></div>';
      }
    }
  }




  $raceid=get_post_meta($post_id,'srp_raceid',true);
  $racename=get_post_meta($post_id,'srp_race_name',true);
  $race_details=json_decode((string)get_post_meta($post_id,'srp_tt_race_details',true),true);
  $course=json_decode((string)get_post_meta($post_id,'srp_tt_course',true),true);
  $marks=json_decode((string)get_post_meta($post_id,'srp_tt_marks',true),true);
  $rows=json_decode((string)get_post_meta($post_id,'srp_parsed_rows',true),true);

  $weather_wind = get_post_meta($post_id, 'srp_weather_wind', true);
  $weather_dir  = get_post_meta($post_id, 'srp_weather_dir', true);
  $weather_temp = get_post_meta($post_id, 'srp_weather_temp', true);
  $weather_pres = get_post_meta($post_id, 'srp_weather_pressure', true);
  
  echo '<div class="wrap"><h1>Race View</h1><p><a href="'.esc_url(admin_url('admin.php?page=srp-race-index')).'">&larr; Back to Race Index</a></p>';

  echo '<form method="post" style="margin: 10px 0 20px 0;">';
  wp_nonce_field('srp_recalc_' . $post_id, 'srp_nonce');
  echo '<input type="hidden" name="srp_action" value="srp_recalc" />';
  echo '<button type="submit" class="button button-secondary">Recalculate SCT / PY / GL</button>';
  echo '</form>';

  $base = admin_url('admin.php?page=srp-race-view&post_id=' . $post_id);
  $url_all = add_query_arg(['method'=>'all'], $base);
  $url_best = add_query_arg(['method'=>'best'], $base);
  echo '<div style="margin:10px 0 14px 0;">';
  echo '<strong>Analysis method:</strong> ';
  echo '<a class="button ' . ($method==='all'?'button-primary':'') . '" href="' . esc_url($url_all) . '">All boats (exclude outliers)</a> ';
  echo '<a class="button ' . ($method==='best'?'button-primary':'') . '" href="' . esc_url($url_best) . '">Best of each class</a>';
  echo '</div>';


  echo '<h2>'.esc_html($racename?$racename:('TT Race '.$raceid)).'</h2>';
  if(is_array($race_details)){
    $start=$race_details['startDateTime']??'';
    $event=$race_details['eventType']??'';
    echo '<p><strong>Race ID:</strong> '.esc_html($raceid).'<br/>';
    if($start) echo '<strong>Start:</strong> '.esc_html($start).'<br/>';
    if($event) echo '<strong>Event Type:</strong> '.esc_html($event).'<br/>';
    echo '</p>';
  }

  // Weather block (editable)
  echo '<h2>Weather</h2>';
  echo '<form method="post" style="max-width: 900px; margin: 0 0 20px 0; padding: 12px; border: 1px solid #ccd0d4; border-radius: 6px; background: #fff;">';
  wp_nonce_field('srp_weather_' . $post_id, 'srp_weather_nonce');
  echo '<input type="hidden" name="srp_action" value="srp_save_weather" />';
  echo '<div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">';
  echo '<label style="display:block; min-width: 170px;"><strong>Wind</strong><br/><input type="text" name="srp_weather_wind" value="' . esc_attr($weather_wind) . '" placeholder="e.g. 12 kt" class="regular-text" /></label>';
  echo '<label style="display:block; min-width: 170px;"><strong>Direction</strong><br/><input type="text" name="srp_weather_dir" value="' . esc_attr($weather_dir) . '" placeholder="e.g. 240°" class="regular-text" /></label>';
  echo '<label style="display:block; min-width: 170px;"><strong>Temperature</strong><br/><input type="text" name="srp_weather_temp" value="' . esc_attr($weather_temp) . '" placeholder="e.g. 9°C" class="regular-text" /></label>';
  echo '<label style="display:block; min-width: 170px;"><strong>Pressure</strong><br/><input type="text" name="srp_weather_pressure" value="' . esc_attr($weather_pres) . '" placeholder="e.g. 1012 hPa" class="regular-text" /></label>';
  echo '<button type="submit" class="button button-primary" style="height: 30px;">Save Weather</button>';
  echo '</div>';
  echo '</form>';

  $points=[]; $course_string=''; $finish_at_start=null; $hazards=[];
  if(is_array($course)){
    $course_string=isset($course['course'])?(string)$course['course']:'';
    $finish_at_start=isset($course['finishAtStart'])?(bool)$course['finishAtStart']:null;
    $hazards=(!empty($course['hazards'])&&is_array($course['hazards']))?$course['hazards']:[];
  }
  if(is_array($marks)){
    foreach($marks as $name=>$loc){ if(is_array($loc)&&isset($loc['lat'])&&isset($loc['long'])) $points[]=['name'=>(string)$name,'lat'=>(float)$loc['lat'],'lng'=>(float)$loc['long'],'type'=>'mark']; }
  }
  if(!empty($hazards)){
    foreach($hazards as $name=>$loc){ if(is_array($loc)&&isset($loc['lat'])&&isset($loc['long'])) $points[]=['name'=>(string)$name,'lat'=>(float)$loc['lat'],'lng'=>(float)$loc['long'],'type'=>'hazard']; }
  }

  echo '<h2>Course & Map</h2>';
  if($course_string) echo '<p><strong>Course:</strong> <code>'.esc_html($course_string).'</code></p>';
  $course_len = srp_format_course_length($course);
  if ($course_len) echo '<p><strong>Course length:</strong> ' . esc_html($course_len) . '</p>';
  if(!is_null($finish_at_start)) echo '<p><strong>Finish at start:</strong> '.($finish_at_start?'Yes':'No').'</p>';
  // Course length from TT payload if available, else calculate from marks + course string
  $course_len = srp_format_course_length($course);
  if ($course_len) {
    echo '<p><strong>Course length:</strong> ' . esc_html($course_len) . '</p>';
  } else {
    $calc_m = srp_course_length_from_marks($course_string, is_array($marks) ? $marks : [], (bool)$finish_at_start);
    if ($calc_m !== null) {
      $nm = $calc_m / 1852.0;
      echo '<p><strong>Course length (from marks):</strong> ' . esc_html(number_format_i18n($calc_m, 0)) . ' m (' . esc_html(number_format_i18n($nm, 2)) . ' nm)</p>';
    }
  }

  // Map rendering can be turned off in Settings if performance is an issue.
  $enable_map = (get_option('srp_enable_map', '1') === '1');
  if(!empty($points) && $enable_map){
    $map_id='srp_race_view_map';
    echo '<div id="'.esc_attr($map_id).'" style="height:420px; max-width:100%; border:1px solid #ccd0d4; border-radius:6px;"></div>';
    $json_points=wp_json_encode($points);
    
$srp_pts = $json_points;
$srp_el  = json_encode($map_id);
$srp_course = wp_json_encode((string)$course_string);

$srp_js = <<<JS
document.addEventListener("DOMContentLoaded", function() {
  if (typeof L === "undefined") return;
  var pts = $srp_pts;
  var el = document.getElementById($srp_el);
  if (!el) return;

  // Expose for debugging
  window.srpMarkPoints = pts;
  var courseStr = $srp_course;
  window.srpCourseStr = courseStr;

  var map = L.map(el);

  // NOTE: Map rotation disabled (north-up) for performance and to avoid confusing the base map.
  function srpGetLatLng(p){
    if(!p) return null;
    if(Array.isArray(p) && p.length>=2){ return {lat:parseFloat(p[0]), lng:parseFloat(p[1])}; }
    if(typeof p==='object' && p.lat!=null && p.lng!=null){ return {lat:parseFloat(p.lat), lng:parseFloat(p.lng)}; }
    if(typeof p==='object' && p[0]!=null && p[1]!=null){ return {lat:parseFloat(p[0]), lng:parseFloat(p[1])}; }
    return null;
  }

  function srpBearing(a,b){
    a = srpGetLatLng(a); b = srpGetLatLng(b);
    if(!a || !b || isNaN(a.lat) || isNaN(a.lng) || isNaN(b.lat) || isNaN(b.lng)) return 0;
    var toRad = function(x){return x*Math.PI/180;};
    var toDeg = function(x){return x*180/Math.PI;};
    var lat1=toRad(a.lat), lat2=toRad(b.lat);
    var dLon=toRad(b.lng-a.lng);
    var y=Math.sin(dLon)*Math.cos(lat2);
    var x=Math.cos(lat1)*Math.sin(lat2)-Math.sin(lat1)*Math.cos(lat2)*Math.cos(dLon);
    return (toDeg(Math.atan2(y,x))+360)%360;
  }

  
  // (Rotation code removed.)

  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {maxZoom:19, attribution:"&copy; OpenStreetMap contributors"}).addTo(map);

  function norm(s){ return (s||"").toString().trim().toLowerCase(); }
  function extractNum(s){ var mm=(s||"").toString().match(/(\d+)/); return mm ? parseInt(mm[1],10) : null; }

  var markByName = {};
  var markByNum = {};
  var b = [];

  pts.forEach(function(p){
    // circle marker + centered permanent label
    var cm = L.circleMarker([p.lat, p.lng], {radius:8, weight:2, opacity:1, fillOpacity:0.7}).addTo(map);
    cm.bindPopup("<strong>"+p.name+"</strong><br/>"+p.type+"<br/>"+p.lat.toFixed(6)+", "+p.lng.toFixed(6));
    cm.bindTooltip(p.name, {permanent:true, direction:"center", className:"srp-mark-label"});

    b.push([p.lat,p.lng]);
    markByName[norm(p.name)] = [p.lat,p.lng];
    var n = extractNum(p.name);
    if (n !== null && !markByNum[n]) markByNum[n] = [p.lat,p.lng];

    // Capture start/finish buoys/pins if present
    var pn = norm(p.name);
    if (pn.indexOf("startboat") !== -1) window.srpStartBoat = [p.lat,p.lng];
    if (pn.indexOf("startpin")  !== -1) window.srpStartPin  = [p.lat,p.lng];
    if (pn === "start") window.srpStart = [p.lat,p.lng];

    if (pn.indexOf("finishboat") !== -1) window.srpFinishBoat = [p.lat,p.lng];
    if (pn.indexOf("finishpin")  !== -1) window.srpFinishPin  = [p.lat,p.lng];
    if (pn === "finish") window.srpFinish = [p.lat,p.lng];

  });

  window.srpMarkByName = markByName;
  window.srpMarkByNum = markByNum;

  if (b.length === 1) map.setView(b[0], 14);
  else if (b.length > 1) map.fitBounds(b, {padding:[20,20]});

  // Draw start/finish lines (StartBoat--StartPin, FinishBoat--FinishPin) if available
  function mid(a,b){ return [(a[0]+b[0])/2, (a[1]+b[1])/2]; }
  if (window.srpStartBoat && window.srpStartPin) {
    var sl = L.polyline([window.srpStartBoat, window.srpStartPin], {weight:2, opacity:0.9, dashArray:"6,4"}).addTo(map);
    L.circleMarker(mid(window.srpStartBoat, window.srpStartPin), {radius:2, opacity:0, fillOpacity:0}).addTo(map)
      .bindTooltip("Start", {permanent:true, direction:"center", className:"srp-leg-label"});
  }
  if (window.srpFinishBoat && window.srpFinishPin) {
    var fl = L.polyline([window.srpFinishBoat, window.srpFinishPin], {weight:2, opacity:0.9, dashArray:"6,4"}).addTo(map);
    L.circleMarker(mid(window.srpFinishBoat, window.srpFinishPin), {radius:2, opacity:0, fillOpacity:0}).addTo(map)
      .bindTooltip("Finish", {permanent:true, direction:"center", className:"srp-leg-label"});
  }


  // Build course points from tokens
  var tokens = (courseStr||"").replace(/[>,;]/g," ").split(/\\s+/).filter(Boolean);
  var linePts = [];
  tokens.forEach(function(t){
    var key = norm(t);
    if (markByName[key]) { linePts.push(markByName[key]); return; }
    var n = extractNum(key);
    if (n !== null && markByNum[n]) linePts.push(markByNum[n]);
  });

  // Close loop for 4-1 etc.
  if (linePts.length >= 2) {
    var first = linePts[0], last = linePts[linePts.length-1];
    if (first[0] !== last[0] || first[1] !== last[1]) linePts.push(first);
  }

  window.srpCoursePts = linePts;
          window.srpCoursePoints = linePts;

  // Draw line + leg angles (Leg 1 = last->first, i.e. closing leg) as 0° reference
  function haversineM(a,b){
    var R=6371000;
    var lat1=a[0]*Math.PI/180, lat2=b[0]*Math.PI/180;
    var dlat=(b[0]-a[0])*Math.PI/180, dlon=(b[1]-a[1])*Math.PI/180;
    var s=Math.sin(dlat/2)*Math.sin(dlat/2)+Math.cos(lat1)*Math.cos(lat2)*Math.sin(dlon/2)*Math.sin(dlon/2);
    var c=2*Math.atan2(Math.sqrt(s),Math.sqrt(1-s));
    return R*c;
  }

  function bearingDeg(a,b){
    var lat1=a[0]*Math.PI/180, lon1=a[1]*Math.PI/180;
    var lat2=b[0]*Math.PI/180, lon2=b[1]*Math.PI/180;
    var y = Math.sin(lon2-lon1)*Math.cos(lat2);
    var x = Math.cos(lat1)*Math.sin(lat2) - Math.sin(lat1)*Math.cos(lat2)*Math.cos(lon2-lon1);
    var brng = Math.atan2(y,x)*180/Math.PI;
    return (brng+360)%360;
  }
  function relAngle(a, ref){
    var d = (a - ref + 360) % 360;
    if (d > 180) d = d - 360;
    return d;
  }

  if (linePts.length >= 2) {
    var pl = L.polyline(linePts, {weight:3, opacity:0.85}).addTo(map);
    try { map.fitBounds(pl.getBounds().pad(0.2)); } catch(e) {}

    // Compute reference bearing for Leg 1: last->first (closing leg) if we have closure
    var refBearing = bearingDeg(linePts[linePts.length-2], linePts[linePts.length-1]);

    for (var i=1; i<linePts.length; i++){
      var a = linePts[i-1], b2 = linePts[i];
      var mid = [(a[0]+b2[0])/2, (a[1]+b2[1])/2];
      var br = bearingDeg(a,b2);
      var rel = relAngle(br, refBearing);
      var legNum = i; // Leg 1 corresponds to closing leg if closure is last segment
      // If course provided 1..n then closure is last leg; label accordingly:
      // i == linePts.length-1 => closing leg
      var meters = haversineM(a,b2);
      var mtxt = meters >= 1000 ? (meters/1000).toFixed(2)+" km" : Math.round(meters)+" m";
      var label;
      if (i === linePts.length-1) {
        label = "Leg 1 (closing): 0°, " + mtxt;
      } else {
        label = "Leg " + (i+1) + ": " + rel.toFixed(0) + "°, " + mtxt;
      }
      L.circleMarker(mid, {radius:0, opacity:0, fillOpacity:0}).addTo(map)
        .bindTooltip(label, {permanent:true, direction:"center", className:"srp-leg-label"});
    }
  }
});
JS;

echo "<style>
.srp-mark-label{background:transparent;border:0;box-shadow:none;color:#111;font-weight:700;font-size:12px;text-shadow:0 1px 2px rgba(255,255,255,0.9);}
.srp-leg-label{background:rgba(255,255,255,0.85);border:1px solid rgba(0,0,0,0.15);box-shadow:none;color:#111;font-weight:600;font-size:11px;}
</style>";

echo "<script>{$srp_js}</script>";

  } else echo '<p><em>No mark coordinates saved for this race.</em></p>';

  echo '<h2>Derived SCT / PY / GL</h2>';
  if(is_array($class_stats)&&!empty($class_stats)){
    echo '<div class="srp-threshold-box"><strong>Outlier thresholds</strong><div id="srp_thresholds_note"></div></div><table id="srp_race_class_stats" class="widefat striped"><thead><tr><th>Class</th><th>SCT</th><th>Derived PY</th><th>Derived PY</th><th>Samples</th><th>Low</th><th>High</th></tr></thead><tbody>';
    foreach($class_stats as $cls=>$st){
      $sct=isset($st['sct_best_lap_s'])?round((float)$st['sct_best_lap_s'],2):'';
      $py=isset($st['derived_py'])?(int)round((float)$st['derived_py']):'';
      $py_best=isset($st['derived_py_best'])?(int)round((float)$st['derived_py_best']):'';
      $n=isset($st['n'])?intval($st['n']):'';
      $low=isset($st['low'])?round((float)$st['low'],2):'';
      $high=isset($st['high'])?round((float)$st['high'],2):'';
      echo '<tr><td>'.esc_html($cls).'</td><td>'.esc_html($sct).'</td><td>'.esc_html($py).'</td><td>'.esc_html($py_best).'</td><td>'.esc_html($n).'</td><td>'.esc_html($low).'</td><td>'.esc_html($high).'</td></tr>';
    }
    echo '</tbody></table>';
  } else echo '<p><em>No class stats saved for this race yet.</em></p>';

  echo '<h2>Imported Results</h2>';
  $view = isset($_GET['view']) ? sanitize_text_field(wp_unslash($_GET['view'])) : '';
  if ($view !== 'raw') $view = 'rya';
  $base_url = admin_url('admin.php?page=srp-race-view&post_id='.(int)$post_id);
  echo '<p style="margin:6px 0 12px 0;">'
    .'<a class="button '.($view==='rya'?'button-primary':'').'" href="'.esc_url($base_url.'&view=rya').'">RYA view</a> '
    .'<a class="button '.($view==='raw'?'button-primary':'').'" href="'.esc_url($base_url.'&view=raw').'">Raw view</a>'
    .'</p>';
  if (is_array($rows) && !empty($rows)) {
    if ($view === 'raw') {
      $headers = array_keys($rows[0]);
      // Hide legacy derived columns from older saves
      $headers = array_values(array_filter($headers, function($h){ return !in_array($h, ['Derived PY','Derived GL'], true); }));

      // Ensure a manual exclusion column exists.
      if (!in_array('Excluded manual', $headers, true)) {
        $headers[] = 'Excluded manual';
      }
      // Add per-row actions
      $headers[] = 'Actions';

      $editable = ['Finish','Elapsed','Laps','GL','Corrected'];

      echo '<table id="srp_race_rows" class="widefat striped"><thead><tr>';
      foreach ($headers as $h) echo '<th>' . esc_html($h) . '</th>';
      echo '</tr></thead><tbody>';
      foreach ($rows as $idx => $r) {
        $is_ex = ((isset($r['Excluded']) && $r['Excluded']==='Yes') || (isset($r['Excluded (All)']) && $r['Excluded (All)']==='Yes'));
        $tr_class = ($is_ex ? ' class="srp-excluded-row"' : '');
        echo '<tr data-row-idx="' . (int)$idx . '"' . $tr_class . '>';
        foreach ($headers as $h) {
          if ($h === 'Actions') {
            echo '<td><button type="button" class="button button-primary srp-row-save" disabled>Save</button></td>';
          } elseif ($h === 'Excluded manual') {
            $val = (string)($r['Excluded manual'] ?? 'No');
            $checked = (strcasecmp($val,'yes')===0) ? 'checked' : '';
            echo '<td style="text-align:center"><input type="checkbox" class="srp-manual-ex" ' . $checked . ' /></td>';
          } elseif (in_array($h, $editable, true)) {
            $cell = (string)($r[$h] ?? '');
            echo '<td><input type="text" class="srp-edit" data-field="' . esc_attr($h) . '" value="' . esc_attr($cell) . '" style="width:100%" /></td>';
          } else {
            echo '<td>' . esc_html($r[$h] ?? '') . '</td>';
          }
        }
        echo '</tr>';
      }
      echo '</tbody></table>';
    } else {
      // RYA condensed view
      $headers = ['RANK','SAIL NUMBER','HELM NAME','CREW NAME','CLASS','CONFIG.','PY','ACHIEVED PY','LAPS','ELAPSED','CORR.','PY DIFF.','Excluded manual','Actions'];

      echo '<table id="srp_race_rows" class="widefat striped"><thead><tr>';
      foreach ($headers as $h) echo '<th>' . esc_html($h) . '</th>';
      echo '</tr></thead><tbody>';

      foreach ($rows as $idx => $r) {
        $rank = $r['rank'] ?? $r['RANK'] ?? '';
        $sail = $r['sail_number'] ?? $r['SAIL NUMBER'] ?? $r['sailno'] ?? '';
        $helm = $r['help_name'] ?? $r['helm_name'] ?? $r['HELM NAME'] ?? $r['helm'] ?? '';
        $crew = $r['crew_name'] ?? $r['CREW NAME'] ?? $r['crew'] ?? '';
        $class = $r['class_name'] ?? $r['class'] ?? $r['CLASS'] ?? '';
        $persons = $r['class_persons'] ?? $r['persons'] ?? '';
        $rig = $r['class_rig'] ?? $r['rig'] ?? '';
        $spin = $r['class_spinnaker'] ?? $r['spinnaker'] ?? '';
        $config = trim((string)$persons) . '|' . trim((string)$rig) . '|' . trim((string)$spin);
        $py = $r['GL'] ?? $r['rating'] ?? $r['PY'] ?? '';
        $ach = $r['Derived PY/GL (All)'] ?? $r['Derived PY/GL'] ?? $r['ACHIEVED PY'] ?? '';
        $laps = $r['Laps'] ?? $r['laps'] ?? $r['laps_norm'] ?? '';
        $elapsed = $r['Elapsed'] ?? $r['elapsed_time'] ?? $r['elapsed'] ?? '';

        $py_f = is_numeric($py) ? (float)$py : 0.0;
        $laps_i = is_numeric($laps) ? max(1, (int)$laps) : 1;
        $elapsed_f = is_numeric($elapsed) ? (float)$elapsed : 0.0;

        // Corrected per lap
        $corr = '';
        if ($py_f > 0 && $elapsed_f > 0) {
          $corr = (int)round((($elapsed_f / $laps_i) * 1000.0) / $py_f);
        }

        $ach_f = is_numeric($ach) ? (float)$ach : null;
        $pydiff = ($ach_f !== null && $py_f > 0) ? (int)round($py_f - $ach_f) : '';

        $manual = (string)($r['Excluded manual'] ?? 'No');
        $checked = (strcasecmp($manual,'yes')===0) ? 'checked' : '';

        echo '<tr data-row-idx="' . (int)$idx . '">';
        echo '<td>' . esc_html($rank) . '</td>';
        echo '<td>' . esc_html($sail) . '</td>';
        echo '<td>' . esc_html($helm) . '</td>';
        echo '<td>' . esc_html($crew) . '</td>';
        echo '<td>' . esc_html($class) . '</td>';
        echo '<td>' . esc_html($config) . '</td>';

        // Editable fields
        echo '<td><input class="srp-edit" data-field="GL" value="' . esc_attr($py) . '" style="width:90px" /></td>';
        echo '<td>' . esc_html($ach) . '</td>';
        echo '<td><input class="srp-edit" data-field="Laps" value="' . esc_attr($laps) . '" style="width:70px" /></td>';
        echo '<td><input class="srp-edit" data-field="Elapsed" value="' . esc_attr($elapsed) . '" style="width:90px" /></td>';
        echo '<td>' . esc_html($corr) . '</td>';
        echo '<td>' . esc_html($pydiff) . '</td>';

        echo '<td style="text-align:center"><input type="checkbox" class="srp-manual-ex" ' . $checked . ' /></td>';
        echo '<td><button type="button" class="button button-primary srp-row-save" disabled>Save</button></td>';
        echo '</tr>';
      }

      echo '</tbody></table>';
    }

    $nonce = wp_create_nonce('srp_row_edit');
    echo '<script>jQuery(function($){
      if($.fn.DataTable){
        var dt = $("#srp_race_rows").DataTable({paging:false, searching:true, info:true, scrollY:"60vh", scrollCollapse:true, scrollX:true, autoWidth:false, fixedHeader:true});
        dt.columns.adjust();
        $(window).on("resize", function(){ dt.columns.adjust(); });
      }
      $("<style>#srp_race_rows_wrapper .dataTables_scrollHead{overflow:visible!important;}</style>").appendTo("head");
      var nonce = ' . wp_json_encode($nonce) . ';
      var postId = ' . (int)$post_id . ';
      function markDirty($tr){ $tr.addClass("srp-dirty"); $tr.find(".srp-row-save").prop("disabled", false); }
      $(document).on("input change", ".srp-edit, .srp-manual-ex", function(){ markDirty($(this).closest("tr")); });
      $(document).on("click", ".srp-row-save", function(){
        var $btn=$(this); var $tr=$btn.closest("tr");
        var rowIdx=parseInt($tr.data("row-idx"),10);
        var fields={};
        $tr.find(".srp-edit").each(function(){ fields[$(this).data("field")]= $(this).val(); });
        var manualExcluded = $tr.find(".srp-manual-ex").is(":checked") ? "1" : "0";
        $btn.prop("disabled", true).text("Saving...");
        $.post(ajaxurl,{action:"srp_save_row_edit",nonce:nonce,post_id:postId,row_idx:rowIdx,fields:fields,manual_excluded:manualExcluded})
          .done(function(resp){ if(resp && resp.success){ window.location.reload(); }
            else { alert((resp&&resp.data&&resp.data.message)?resp.data.message:"Save failed"); $btn.prop("disabled",false).text("Save"); } })
          .fail(function(){ alert("Save failed"); $btn.prop("disabled",false).text("Save"); });
      });
    });</script>';
  } else {
    echo '<p><em>No rows saved for this race.</em></p>';
  }
}

function srp_render_import_ui(array $opts) {
  $context=$opts['context']??'admin';
  $action=isset($_POST['srp_action'])?sanitize_text_field(wp_unslash($_POST['srp_action'])):'parse';
  $html=isset($_POST['srp_html'])?wp_unslash($_POST['srp_html']):'';
  $raceid=isset($_POST['srp_raceid'])?sanitize_text_field(wp_unslash($_POST['srp_raceid'])):'';

  $parse_result=null; $race_result=null; $course_result=null; $marks_result=null; $stats_result=null;
  $saved_post_id=null; $error='';

  $posted_context=isset($_POST['srp_context'])?sanitize_text_field(wp_unslash($_POST['srp_context'])):'';
  $should_handle=($_SERVER['REQUEST_METHOD']==='POST' && $posted_context===$context);

  if($should_handle){
    if(!isset($_POST['srp_nonce'])||!wp_verify_nonce($_POST['srp_nonce'],'srp_parse_'.$context)) $error='Security check failed.';
    else if($raceid===''||!preg_match('/^\d+$/',$raceid)) $error='Please enter a numeric TT raceid.';
    else if(trim($html)==='') $error='Please paste some HTML (must include a results table).';
    else{
      $parse_result=srp_parse_results_html($html);
      if(!empty($parse_result['error'])) $error=$parse_result['error'];
      else{
        $race_result=srp_tt_fetch_race_details($raceid);
        $course_result=srp_tt_fetch_course($raceid);
        $marks_result=srp_tt_fetch_marks($raceid);

        $k=floatval(get_option('srp_outlier_k',2.0));
        $stats_result=srp_compute_dual($parse_result['rows'],$k);
        if(!empty($stats_result['rows'])) $parse_result['rows']=$stats_result['rows'];

        if($action==='save'){
          $saved_post_id=srp_save_or_update_import($raceid,$html,$parse_result,$race_result,$course_result,$marks_result,$stats_result);
          if(is_wp_error($saved_post_id)){ $error=$saved_post_id->get_error_message(); $saved_post_id=null; }
        }
      }
    }
  }

  if($error){
    echo '<div class="notice notice-error"><p>'.esc_html($error).'</p></div>';
  } elseif($saved_post_id){
    $view=admin_url('admin.php?page=srp-race-view&post_id='.intval($saved_post_id));
    echo '<div class="notice notice-success"><p>Saved import (created/updated). <a href="'.esc_url($view).'">Open race view</a></p></div>';
  }

  echo '<form method="post">';
  wp_nonce_field('srp_parse_'.$context,'srp_nonce');
  echo '<input type="hidden" name="srp_context" value="'.esc_attr($context).'" />';
  echo '<p><label><strong>TT raceid</strong></label><br/><input type="text" name="srp_raceid" value="'.esc_attr($raceid).'" class="regular-text" /></p>';
  echo '<p><strong>Results HTML</strong></p>';

  $settings=['textarea_name'=>'srp_html','textarea_rows'=>18,'media_buttons'=>false,'teeny'=>true,'tinymce'=>['wpautop'=>false,'forced_root_block'=>false,'toolbar1'=>'bold,italic,underline,|,bullist,numlist,|,removeformat,|,undo,redo,|,code','toolbar2'=>''],'quicktags'=>true];
  wp_editor($html,'srp_html_editor_'.$context,$settings);

  echo '<p><button type="submit" class="button button-secondary" name="srp_action" value="parse">Parse</button> <button type="submit" class="button button-primary" name="srp_action" value="save">Save</button></p>';
  echo '</form>';

  if(is_array($parse_result)){
    echo '<h2>Preview</h2>';
    if(!empty($parse_result['rows'])){
      $headers=array_keys($parse_result['rows'][0]);
      $headers = array_values(array_filter($headers, function($h){ return !in_array($h, ['Derived PY','Derived GL'], true); }));
      echo '<div style="overflow:auto; max-width:100%;"><table class="widefat striped"><thead><tr>';
      foreach($headers as $h) echo '<th>'.esc_html($h).'</th>';
      echo '</tr></thead><tbody>';
      foreach($parse_result['rows'] as $row){ echo '<tr>'; foreach($headers as $h) echo '<td>'.esc_html($row[$h]??'').'</td>'; echo '</tr>'; }
      echo '</tbody></table></div>';
    } else echo '<p><em>No rows could be extracted.</em></p>';
  }
}

function srp_find_import_post_id_by_raceid($raceid) {
  $q=new WP_Query(['post_type'=>'srp_import','post_status'=>['private'],'posts_per_page'=>1,'meta_key'=>'srp_raceid','meta_value'=>$raceid,'fields'=>'ids']);
  if(!empty($q->posts)) return (int)$q->posts[0];
  return 0;
}

function srp_save_or_update_import($raceid,$html,array $parse_result,$race_result,$course_result,$marks_result,$stats_result) {
  $race_name='';
  if(is_array($race_result)&&empty($race_result['error'])){
    if(!empty($race_result['raceName'])) $race_name=(string)$race_result['raceName'];
    if($race_name===''&&!empty($race_result['name'])) $race_name=(string)$race_result['name'];
  }
  $title='TT Race '.$raceid.($race_name?' — '.$race_name:'');
  $existing_id=srp_find_import_post_id_by_raceid($raceid);
  if($existing_id) $post_id=wp_update_post(['ID'=>$existing_id,'post_title'=>$title,'post_status'=>'private','post_type'=>'srp_import'],true);
  else $post_id=wp_insert_post(['post_type'=>'srp_import','post_status'=>'private','post_title'=>$title],true);
  if(is_wp_error($post_id)) return $post_id;

  update_post_meta($post_id,'srp_raceid',$raceid);
  update_post_meta($post_id,'srp_race_name',$race_name);
  update_post_meta($post_id,'srp_raw_html',$html);
  update_post_meta($post_id,'srp_parsed_rows',wp_json_encode($parse_result['rows']));

  if(is_array($race_result)&&empty($race_result['error'])) update_post_meta($post_id,'srp_tt_race_details',wp_json_encode($race_result));
  if(is_array($course_result)&&empty($course_result['error'])) update_post_meta($post_id,'srp_tt_course',wp_json_encode($course_result));
  if(is_array($marks_result)&&empty($marks_result['error'])) update_post_meta($post_id,'srp_tt_marks',wp_json_encode($marks_result));

  if(is_array($stats_result)&&!empty($stats_result['class_stats'])){
    srp_upsert_class_stats($stats_result['class_stats']);
    update_post_meta($post_id,'srp_class_stats',wp_json_encode($stats_result['class_stats']));
  }

  return $post_id;
}

function srp_upsert_class_stats(array $class_stats) {
  global $wpdb;
  $table=$wpdb->prefix.'srp_class_stats';
  $now=current_time('mysql');

  foreach($class_stats as $cls=>$st){
    $class_name=(string)$cls;
    $derived_py=isset($st['derived_py'])?floatval($st['derived_py']):null;
    $derived_gl=$derived_py; // GL same as PY
    $sct=isset($st['sct_avg_lap_s'])?floatval($st['sct_avg_lap_s']):null;
    $n=isset($st['n'])?intval($st['n']):null;
    $k=isset($st['k'])?floatval($st['k']):null;

    $wpdb->replace($table,[
      'class_name'=>$class_name,
      'derived_py'=>$derived_py,
      'derived_gl'=>$derived_gl,
      'sct_avg_lap_s'=>$sct,
      'sample_n'=>$n,
      'outlier_k'=>$k,
      'updated_at'=>$now,
    ],['%s','%f','%f','%f','%d','%f','%s']);
  }
}


add_action('admin_menu', function(){
  add_submenu_page(null, 'Class Graph', 'Class Graph', 'manage_options', 'srp-class-graph', 'srp_class_graph_page');
});




function srp_class_graph_page(){
  if (!current_user_can('edit_posts')) return;
  $class = isset($_GET['class']) ? sanitize_text_field($_GET['class']) : '';
  if (!$class){
    echo '<div class="wrap"><h1>No class specified</h1></div>';
    return;
  }

  $points = [];
  $race_posts = get_posts([
    'post_type' => 'srp_import',
    'post_status' => ['publish','private','draft'],
    'numberposts' => -1,
    'fields' => 'ids',
  ]);

  foreach ($race_posts as $pid){
    $cs = json_decode((string)get_post_meta($pid,'srp_class_stats',true),true);
    if (!is_array($cs) || !isset($cs[$class])) continue;

    // date
    $details = json_decode((string)get_post_meta($pid,'srp_tt_race_details',true),true);
    $ts=null;
    if (is_array($details)){
      foreach ($details as $k=>$v){
        $lk = strtolower((string)$k);
        if (in_array($lk, ['start','starttime','start_time','startdatetime','start_date','racestart'], true) || strpos($lk,'start') !== false){
          $ts=strtotime((string)$v);
          if ($ts) break;
        }
      }
    }
    if (!$ts) continue;

    $derived = isset($cs[$class]['derived_py']) ? floatval($cs[$class]['derived_py']) : null;

    // Actual PY/GL used: median GL from stored parsed rows for this class in this race
    $rows = json_decode((string)get_post_meta($pid,'srp_parsed_rows',true),true);
    if (!is_array($rows)) $rows = [];
    $gls = [];
    foreach ($rows as $r){
      $bn = isset($r['boatname']) ? (string)$r['boatname'] : (isset($r['Boat']) ? (string)$r['Boat'] : '');
      if ($bn === '') continue;
      if (strcasecmp($bn, $class) !== 0) continue;
      $gl = null;
      if (isset($r['gl'])) $gl = floatval($r['gl']);
      elseif (isset($r['GL'])) $gl = floatval($r['GL']);
      if ($gl !== null && $gl > 0) $gls[] = $gl;
    }
    sort($gls);
    $actual = null;
    $cnt = count($gls);
    if ($cnt > 0){
      $mid = intdiv($cnt,2);
      $actual = ($cnt % 2) ? $gls[$mid] : (($gls[$mid-1]+$gls[$mid])/2.0);
    }

    $points[]=['ts'=>$ts,'derived'=> (is_numeric($derived)? (int)round($derived): null), 'actual'=> (is_numeric($actual)? (int)round($actual): null)];
  }

  usort($points,function($a,$b){ return $a['ts'] <=> $b['ts']; });

  // Filter rogue points: invalid dates and derived outliers (SD)
  $min_ts = strtotime('2000-01-01');
  $max_ts = time() + 86400; // allow 1 day future
  $points = array_values(array_filter($points, function($p) use ($min_ts,$max_ts){
    return isset($p['ts']) && is_numeric($p['ts']) && $p['ts'] >= $min_ts && $p['ts'] <= $max_ts;
  }));
  // SD filter on derived
  $derived_vals = [];
  foreach ($points as $p){
    if (isset($p['derived']) && is_numeric($p['derived'])) $derived_vals[] = (float)$p['derived'];
  }
  if (count($derived_vals) >= 5){
    $mean = array_sum($derived_vals)/count($derived_vals);
    $var = 0.0;
    foreach ($derived_vals as $v){ $var += ($v-$mean)*($v-$mean); }
    $sd = sqrt($var/(count($derived_vals)-1));
    $k = 3.0;
    if ($sd > 0){
      $low = $mean - $k*$sd;
      $high = $mean + $k*$sd;
      $points = array_values(array_filter($points, function($p) use ($low,$high){
        if (!isset($p['derived']) || !is_numeric($p['derived'])) return true;
        $v=(float)$p['derived'];
        return ($v >= $low && $v <= $high);
      }));
    }
  }



  $dates=[]; $derived_vals=[]; $actual_vals=[]; $x=[]; $y=[];
  foreach ($points as $i=>$p){
    $dates[]=date('Y-m-d',$p['ts']);
    $derived_vals[]=$p['derived'];
    $actual_vals[]=$p['actual'];
    $x[]=$i;
    $y[]=$p['derived'];
  }

  // Trendline on derived series (ignoring nulls)
  $trend=[];
  $n=count($x);
  if ($n>1){
    $xs=[]; $ys=[];
    for($i=0;$i<$n;$i++){
      if ($y[$i] === null) continue;
      $xs[]=$x[$i]; $ys[]=$y[$i];
    }
    $nn=count($xs);
    if ($nn>1){
      $sumx=array_sum($xs);
      $sumy=array_sum($ys);
      $sumxy=0; $sumxx=0;
      for($i=0;$i<$nn;$i++){
        $sumxy += $xs[$i]*$ys[$i];
        $sumxx += $xs[$i]*$xs[$i];
      }
      $den = ($nn*$sumxx - $sumx*$sumx);
      if ($den != 0){
        $m = ($nn*$sumxy - $sumx*$sumy)/$den;
        $b = ($sumy - $m*$sumx)/$nn;
        for($i=0;$i<$n;$i++){
          $trend[] = ($y[$i] === null) ? null : ($m*$x[$i] + $b);
        }
      }
    }
  }

  // Enqueue Chart.js and inline init (no admin_enqueue timing issues)
  wp_enqueue_script('srp-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
  $inline = 'document.addEventListener("DOMContentLoaded",function(){'
    . 'var el=document.getElementById("srp_py_chart"); if(!el) return;'
    . 'if(typeof Chart==="undefined"){ console.warn("Chart.js not loaded"); return; }'
    . 'var labels=' . wp_json_encode($dates) . ';'
    . 'var derived=' . wp_json_encode($derived_vals) . ';'
    . 'var actual=' . wp_json_encode($actual_vals) . ';'
    . 'var trend=' . wp_json_encode($trend) . ';'
    . 'new Chart(el,{type:"line",data:{labels:labels,datasets:['
    . '{label:"Derived PY",data:derived,tension:0.1},'
    . '{label:"Actual PY used",data:actual,tension:0.1},'
    . '{label:"Trendline (derived)",data:trend,borderDash:[5,5],tension:0.1}'
    . ']},options:{responsive:true,plugins:{legend:{display:true}},scales:{y:{title:{display:true,text:"PY"}}}}});'
    . '});';
  wp_add_inline_script('srp-chartjs', $inline);

  echo '<div class="wrap">';
  echo '<h1>'.esc_html($class).' – PY over time</h1>';
  echo '<p>Toggle series by clicking legend.</p>';
  if (count($dates) < 2){
    echo '<p>No (or not enough) saved race points for this class yet. Recalculate a few races first.</p>';
  }
  echo '<canvas id="srp_py_chart" style="max-width:1100px;height:360px;"></canvas>';
  echo '</div>';
}








add_action('admin_head', function(){
  if (!isset($_GET['page'])) return;
  if (strpos($_GET['page'], 'srp-') !== 0 && $_GET['page'] !== 'srp_race') return;
  echo '<style>
    tr.srp-excluded-row { opacity: 0.45; }
    tr.srp-excluded-row td { background: #f3f3f3 !important; }
    .srp-threshold-box{margin:10px 0 16px 0;padding:10px 12px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;}
    .srp-threshold-box code{font-size:12px;}
  </style>';
});

function srp_tools_page(){
  if (!current_user_can('edit_posts')) return;
  echo '<div class="wrap"><h1>SRP Tools</h1>';
  if (isset($_GET['srp_done'])){
    echo '<div class="notice notice-success"><p>Recalculation completed.</p></div>';
  }
  echo '<p>This recalculates SCT / Derived PY/GL for all stored races using the latest logic.</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  echo '<input type="hidden" name="action" value="srp_recalc_all">';
  wp_nonce_field('srp_recalc_all','srp_nonce');
  echo '<p><button class="button button-primary">Recalculate all races</button></p>';
  echo '</form></div>';
}

add_action('admin_post_srp_recalc_all', function(){
  if (!current_user_can('edit_posts')) wp_die('Not allowed');
  if (!isset($_POST['srp_nonce']) || !wp_verify_nonce($_POST['srp_nonce'], 'srp_recalc_all')) wp_die('Bad nonce');

  $posts = get_posts([
    'post_type' => 'srp_race_import',
    'post_status' => 'any',
    'numberposts' => -1,
    'fields' => 'ids',
  ]);
  if (is_array($posts)){
    foreach ($posts as $post_id){
      $rows = json_decode((string)get_post_meta($post_id, 'srp_parsed_rows', true), true);
      if (!is_array($rows) || empty($rows)) continue;
      if (function_exists('srp_compute_dual_analysis')){
        $dual = srp_compute_dual_analysis($rows);
        if (is_array($dual)){
          update_post_meta($post_id, 'srp_analysis_dual', $dual);
          if (isset($dual['all']['rows'])) update_post_meta($post_id, 'srp_parsed_rows', wp_json_encode($dual['all']['rows']));
          if (isset($dual['all']['class_stats'])) update_post_meta($post_id, 'srp_class_stats', wp_json_encode($dual['all']['class_stats']));
          if (isset($dual['all']['debug'])) update_post_meta($post_id, 'srp_calc_debug', wp_json_encode($dual['all']['debug']));
        }
      }
    }
  }
  wp_safe_redirect(add_query_arg(['page'=>'srp-tools','srp_done'=>1], admin_url('admin.php')));
  exit;
});

/**
 * Save per-row manual edits (Finish / Elapsed / Laps / GL etc.) and manual exclusion.
 * Recomputes stored SCT/PY/GL immediately so rogue rows cannot pollute class stats.
 */
function srp_ajax_save_row_edit(){
  if (!current_user_can('edit_posts')){
    wp_send_json_error(['message' => 'Permission denied'], 403);
  }
  $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
  if (!wp_verify_nonce($nonce, 'srp_row_edit')){
    wp_send_json_error(['message' => 'Bad nonce'], 400);
  }

  $post_id = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
  $row_idx = isset($_POST['row_idx']) ? (int)$_POST['row_idx'] : -1;
  if ($post_id <= 0 || $row_idx < 0){
    wp_send_json_error(['message' => 'Missing post_id/row_idx'], 400);
  }

  $rows = json_decode((string)get_post_meta($post_id, 'srp_parsed_rows', true), true);
  if (!is_array($rows) || !isset($rows[$row_idx]) || !is_array($rows[$row_idx])){
    wp_send_json_error(['message' => 'Row not found'], 404);
  }

  $fields = isset($_POST['fields']) ? (array)$_POST['fields'] : [];
  // Expect a small set of editable columns only.
  $allowed = ['Finish','Elapsed','Laps','GL','Corrected'];
  foreach ($allowed as $k){
    if (array_key_exists($k, $fields)){
      $rows[$row_idx][$k] = sanitize_text_field(wp_unslash((string)$fields[$k]));
    }
  }

  $manual_ex = isset($_POST['manual_excluded']) ? sanitize_text_field(wp_unslash((string)$_POST['manual_excluded'])) : '0';
  $rows[$row_idx]['Excluded manual'] = ($manual_ex === '1' || $manual_ex === 'true' || strcasecmp($manual_ex,'yes')===0) ? 'Yes' : 'No';

  update_post_meta($post_id, 'srp_parsed_rows', wp_json_encode($rows));

  // Recompute derived PY/GL immediately after edits
  $k = floatval(get_option('srp_outlier_k', 2.0));
  $norm_rows = $rows;

  // Normalize common RYA field names into canonical keys used by stats.php
  foreach ($norm_rows as $i => $r) {
    if (!isset($norm_rows[$i]['GL']) || $norm_rows[$i]['GL'] === '') {
      if (isset($r['rating']) && $r['rating'] !== '') $norm_rows[$i]['GL'] = $r['rating'];
      elseif (isset($r['PY']) && $r['PY'] !== '') $norm_rows[$i]['GL'] = $r['PY'];
    }
    if (!isset($norm_rows[$i]['Elapsed']) || $norm_rows[$i]['Elapsed'] === '') {
      if (isset($r['elapsed_time']) && $r['elapsed_time'] !== '') $norm_rows[$i]['Elapsed'] = $r['elapsed_time'];
      elseif (isset($r['elapsed']) && $r['elapsed'] !== '') $norm_rows[$i]['Elapsed'] = $r['elapsed'];
    }
    if (!isset($norm_rows[$i]['Laps']) || $norm_rows[$i]['Laps'] === '') {
      if (isset($r['laps']) && $r['laps'] !== '') $norm_rows[$i]['Laps'] = $r['laps'];
      elseif (isset($r['laps_norm']) && $r['laps_norm'] !== '') $norm_rows[$i]['Laps'] = $r['laps_norm'];
    }
    if (!isset($norm_rows[$i]['class']) || $norm_rows[$i]['class'] === '') {
      if (isset($r['class_name']) && $r['class_name'] !== '') $norm_rows[$i]['class'] = $r['class_name'];
      elseif (isset($r['Class']) && $r['Class'] !== '') $norm_rows[$i]['class'] = $r['Class'];
    }
    if (!isset($norm_rows[$i]['elapsed']) || $norm_rows[$i]['elapsed'] === '') {
      if (isset($norm_rows[$i]['Elapsed'])) $norm_rows[$i]['elapsed'] = $norm_rows[$i]['Elapsed'];
    }
    if (!isset($norm_rows[$i]['laps_norm']) || $norm_rows[$i]['laps_norm'] === '') {
      if (isset($norm_rows[$i]['Laps'])) $norm_rows[$i]['laps_norm'] = $norm_rows[$i]['Laps'];
    }
    if (!isset($norm_rows[$i]['py']) || $norm_rows[$i]['py'] === '') {
      if (isset($norm_rows[$i]['GL'])) $norm_rows[$i]['py'] = $norm_rows[$i]['GL'];
    }
  }

  if (function_exists('srp_compute_dual')) {
    $dual = srp_compute_dual($norm_rows, $k);
    // Prefer the ALL method as the primary saved dataset
    $updated_rows = $dual['all']['rows'] ?? $norm_rows;
    $class_stats  = $dual['all']['class_stats'] ?? [];
    $debug        = $dual['all']['debug'] ?? [];

    // Ensure derived columns exist on every row so the header always shows them
    $must_cols = [
      'Derived PY/GL','Derived PY/GL (All)','Derived PY/GL (Best)',
      'Excluded','Excluded (All)','Excluded (Best)',
      'Excluded Reason (All)','Excluded Reason (Best)'
    ];
    foreach ($updated_rows as $ri => $rr) {
      foreach ($must_cols as $c) {
        if (!array_key_exists($c, $updated_rows[$ri])) $updated_rows[$ri][$c] = '';
      }
    }

    update_post_meta($post_id, 'srp_parsed_rows', wp_json_encode($updated_rows));
    update_post_meta($post_id, 'srp_class_stats', wp_json_encode($class_stats));
    update_post_meta($post_id, 'srp_calc_debug', wp_json_encode($debug));
  }

  wp_send_json_success(['ok'=>true]);
}

// -----------------------------------------------------------------------------
// Race Index actions: overwrite + delete
// -----------------------------------------------------------------------------
add_action('admin_post_srp_delete_import', 'srp_delete_import_handler');
function srp_delete_import_handler() {
  if (!current_user_can('delete_posts')) wp_die('Not allowed');
  $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
  if (!$post_id) wp_die('Missing post_id');
  check_admin_referer('srp_delete_import_' . $post_id);
  wp_trash_post($post_id);
  wp_redirect(admin_url('admin.php?page=srp-race-index&deleted=1'));
  exit;
}

add_action('admin_post_srp_overwrite_import', 'srp_overwrite_import_handler');
function srp_overwrite_import_handler() {
  if (!current_user_can('edit_posts')) wp_die('Not allowed');
  $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
  if (!$post_id) wp_die('Missing post_id');
  check_admin_referer('srp_overwrite_import_' . $post_id);

  $raceid = get_post_meta($post_id, 'srp_raceid', true);
  if (!$raceid) wp_die('Missing raceid');

  // Re-fetch TT details/course/marks and overwrite stored meta
  $race = srp_tt_fetch_race_details($raceid);
  if (is_array($race) && empty($race['error'])) update_post_meta($post_id, 'srp_tt_race_details', wp_json_encode($race));

  $course = srp_tt_fetch_course($raceid);
  if (is_array($course) && empty($course['error'])) update_post_meta($post_id, 'srp_tt_course', wp_json_encode($course));

  $marks = srp_tt_fetch_marks($raceid);
  if (is_array($marks) && empty($marks['error'])) update_post_meta($post_id, 'srp_tt_marks', wp_json_encode($marks));

  wp_redirect(admin_url('admin.php?page=srp-race-view&post_id=' . $post_id . '&overwritten=1'));
  exit;
}



/**
 * Data QA: find suspicious rows (e.g., typos in elapsed time) across all imports.
 * Heuristic: per-lap elapsed far above the race median, or absolute elapsed very large.
 */
function srp_data_qa_page() {
  if (!current_user_can('manage_options')) {
    wp_die('Sorry, you are not allowed to access this page.');
  }

  // Handle filters
  $days = isset($_GET['days']) ? max(1, intval($_GET['days'])) : 3650; // default: ~10 years
  $mult = isset($_GET['mult']) ? max(2, floatval($_GET['mult'])) : 3.0; // per-lap multiple vs median
  $abs  = isset($_GET['abs']) ? max(3600, intval($_GET['abs'])) : 8*3600; // absolute elapsed threshold (seconds), default 8h
  $limit= isset($_GET['limit']) ? max(50, intval($_GET['limit'])) : 500;

  $since = gmdate('Y-m-d\TH:i:s', time() - ($days*86400));

  $q = new WP_Query([
    'post_type'      => 'srp_import',
    'post_status'    => ['publish','draft','pending','private','future'],
    'posts_per_page' => $limit,
    'orderby'        => 'modified',
    'order'          => 'DESC',
    'meta_query'     => [
      [
        'key'     => 'srp_tt_race_details',
        'compare' => 'EXISTS',
      ]
    ],
  ]);

  echo '<div class="wrap"><h1>Data QA</h1>';
  echo '<p>Flags suspicious result rows (e.g., typo elapsed time). This does not change data until you save a correction.</p>';

  // Filter form
  echo '<form method="get" style="margin:12px 0; padding:10px; background:#fff; border:1px solid #ddd;">';
  echo '<input type="hidden" name="page" value="srp-data-qa" />';
  echo '<label style="margin-right:10px;">Look back days <input type="number" name="days" value="'.esc_attr($days).'" min="1" style="width:110px;"></label>';
  echo '<label style="margin-right:10px;">Per-lap &gt; median × <input type="number" step="0.1" name="mult" value="'.esc_attr($mult).'" min="2" style="width:90px;"></label>';
  echo '<label style="margin-right:10px;">Elapsed &gt; (sec) <input type="number" name="abs" value="'.esc_attr($abs).'" min="3600" style="width:110px;"></label>';
  echo '<label style="margin-right:10px;">Scan max races <input type="number" name="limit" value="'.esc_attr($limit).'" min="50" style="width:110px;"></label>';
  echo '<button class="button button-primary">Refresh</button>';
  echo '</form>';

  $flags = [];

  foreach ($q->posts as $post) {
    $post_id = $post->ID;
    $raceid  = get_post_meta($post_id, 'srp_raceid', true);
    $racenm  = get_post_meta($post_id, 'srp_race_name', true);
    $start   = '';
    $rd_json = get_post_meta($post_id, 'srp_tt_race_details', true);
    if ($rd_json) {
      $rd = json_decode($rd_json, true);
      if (is_array($rd)) {
        $start = $rd['startDateTime'] ?? $rd['start'] ?? '';
      }
    }
    if ($start === '') $start = get_post_meta($post_id, 'srp_start', true);

    $rows_json = get_post_meta($post_id, 'srp_parsed_rows', true);
    if (!$rows_json) continue;
    $rows = json_decode($rows_json, true);
    if (!is_array($rows) || empty($rows)) continue;

    // Collect per-lap elapsed values for median
    $perlaps = [];
    foreach ($rows as $r) {
      $elapsed = $r['Elapsed'] ?? $r['elapsed_time'] ?? $r['elapsed'] ?? null;
      $laps    = $r['Laps'] ?? $r['laps'] ?? $r['laps_norm'] ?? 1;
      $es = is_numeric($elapsed) ? floatval($elapsed) : srp_time_to_seconds($elapsed);
      $li = max(1, intval($laps));
      if ($es !== null && $es > 0) $perlaps[] = $es / $li;
    }
    if (count($perlaps) < 5) continue;
    sort($perlaps);
    $mid = intdiv(count($perlaps), 2);
    $median = (count($perlaps) % 2) ? $perlaps[$mid] : (($perlaps[$mid-1] + $perlaps[$mid]) / 2.0);
    if ($median <= 0) continue;

    foreach ($rows as $i => $r) {
      $elapsed = $r['Elapsed'] ?? $r['elapsed_time'] ?? $r['elapsed'] ?? null;
      $laps    = $r['Laps'] ?? $r['laps'] ?? $r['laps_norm'] ?? 1;
      $es = is_numeric($elapsed) ? floatval($elapsed) : srp_time_to_seconds($elapsed);
      $li = max(1, intval($laps));
      if ($es === null || $es <= 0) continue;

      $perlap = $es / $li;

      $reason = '';
      if ($es >= $abs) {
        $reason = 'Elapsed very large (' . intval($es) . 's)';
      } elseif ($perlap >= ($median * $mult)) {
        $reason = 'Per-lap elapsed ' . round($perlap) . 's is > median ' . round($median) . 's × ' . $mult;
      }

      if ($reason !== '') {
        $flags[] = [
          'post_id' => $post_id,
          'row_i'   => $i,
          'raceid'  => (string)$raceid,
          'racenm'  => (string)$racenm,
          'start'   => (string)$start,
          'sail'    => (string)($r['SAIL NUMBER'] ?? $r['sail_number'] ?? $r['Sail Number'] ?? $r['SailNo'] ?? ''),
          'helm'    => (string)($r['HELM NAME'] ?? $r['help_name'] ?? $r['Helm'] ?? $r['helm'] ?? ''),
          'crew'    => (string)($r['CREW NAME'] ?? $r['crew_name'] ?? $r['Crew'] ?? $r['crew'] ?? ''),
          'class'   => (string)($r['CLASS'] ?? $r['class_name'] ?? $r['class'] ?? ''),
          'laps'    => $li,
          'elapsed' => $es,
          'perlap'  => $perlap,
          'reason'  => $reason,
        ];
      }
    }
  }

  if (empty($flags)) {
    echo '<p><strong>No suspicious rows found</strong> using the current thresholds.</p></div>';
    return;
  }

  // Output table
  echo '<table class="widefat striped" style="max-width: 1400px;">';
  echo '<thead><tr>';
  echo '<th>Race</th><th>Start</th><th>Sail</th><th>Helm</th><th>Crew</th><th>Class</th><th>Laps</th><th>Elapsed (s)</th><th>Per lap (s)</th><th>Reason</th><th>Fix</th><th>Open</th>';
  echo '</tr></thead><tbody>';

  $nonce = wp_create_nonce('srp_fix_row');
  foreach ($flags as $f) {
    $view_url = admin_url('admin.php?page=srp-race-view&post_id=' . intval($f['post_id']));
    echo '<tr>';
    $race_label = trim($f['racenm']) !== '' ? esc_html($f['racenm']) : ('Race ' . esc_html($f['raceid']));
    echo '<td>' . $race_label . '</td>';
    echo '<td>' . esc_html(substr((string)$f['start'], 0, 19)) . '</td>';
    echo '<td>' . esc_html($f['sail']) . '</td>';
    echo '<td>' . esc_html($f['helm']) . '</td>';
    echo '<td>' . esc_html($f['crew']) . '</td>';
    echo '<td>' . esc_html($f['class']) . '</td>';
    echo '<td>' . esc_html((string)$f['laps']) . '</td>';
    echo '<td>' . esc_html((string)round($f['elapsed'])) . '</td>';
    echo '<td>' . esc_html((string)round($f['perlap'])) . '</td>';
    echo '<td style="max-width:360px;">' . esc_html($f['reason']) . '</td>';

    echo '<td>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex; gap:6px; align-items:center;">';
    echo '<input type="hidden" name="action" value="srp_fix_row" />';
    echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'" />';
    echo '<input type="hidden" name="post_id" value="'.esc_attr($f['post_id']).'" />';
    echo '<input type="hidden" name="row_i" value="'.esc_attr($f['row_i']).'" />';
    echo '<input type="number" name="laps" value="'.esc_attr($f['laps']).'" min="1" style="width:70px;" />';
    echo '<input type="number" name="elapsed" value="'.esc_attr((int)round($f['elapsed'])).'" min="1" style="width:110px;" />';
    echo '<button class="button">Save</button>';
    echo '</form>';
    echo '</td>';

    echo '<td><a class="button" href="'.esc_url($view_url).'" target="_blank">View</a></td>';
    echo '</tr>';
  }

  echo '</tbody></table>';
  echo '</div>';
}

add_action('admin_post_srp_fix_row', function () {
  if (!current_user_can('manage_options')) wp_die('Sorry, you are not allowed to do this.');
  check_admin_referer('srp_fix_row');

  $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
  $row_i   = isset($_POST['row_i']) ? intval($_POST['row_i']) : -1;
  $laps    = isset($_POST['laps']) ? max(1, intval($_POST['laps'])) : 1;
  $elapsed = isset($_POST['elapsed']) ? max(1, floatval($_POST['elapsed'])) : 0;

  if ($post_id <= 0 || $row_i < 0) wp_die('Invalid request.');

  $rows_json = get_post_meta($post_id, 'srp_parsed_rows', true);
  $rows = $rows_json ? json_decode($rows_json, true) : null;
  if (!is_array($rows) || !isset($rows[$row_i]) || !is_array($rows[$row_i])) wp_die('Row not found.');

  // Update both canonical + original keys where possible
  $rows[$row_i]['Laps'] = $laps;
  $rows[$row_i]['laps'] = $laps;
  $rows[$row_i]['Elapsed'] = $elapsed;
  $rows[$row_i]['elapsed_time'] = $elapsed;
  $rows[$row_i]['elapsed'] = $elapsed;

  // Normalize and recompute derived stats
  if (function_exists('srp_rya_normalize_rows_for_stats')) {
    $rows = srp_rya_normalize_rows_for_stats($rows);
  }
  $dual = srp_compute_dual($rows, 2.0);
  // Prefer "all" output for saved rows (matches UI)
  $rows_out = $dual['all']['rows'] ?? $rows;
  if (function_exists('srp_ensure_stat_columns')) $rows_out = srp_ensure_stat_columns($rows_out);

  update_post_meta($post_id, 'srp_parsed_rows', wp_json_encode($rows_out));
  // Save class stats (all + best) for the page
  $class_stats = [
    'all'  => $dual['all']['class_stats'] ?? [],
    'best' => $dual['best']['class_stats'] ?? [],
    'debug'=> $dual['all']['debug'] ?? [],
  ];
  update_post_meta($post_id, 'srp_class_stats', wp_json_encode($class_stats));

  wp_safe_redirect(admin_url('admin.php?page=srp-data-qa&updated=1'));
  exit;
});

