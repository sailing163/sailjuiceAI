<?php
if (!defined('ABSPATH')) exit;

function srp_tt_base_url() { return 'https://tacktracker.com/api/'; }
function srp_tt_api_key() { return get_option('srp_tt_api_key', ''); }

function srp_tt_request_json($path, $debug = false) {
  $api_key = srp_tt_api_key();
  if ($api_key === '') return ['error' => 'Missing TackTracker API key (set it in Sailing Results -> Settings).'];

  $url = trailingslashit(srp_tt_base_url()) . ltrim($path, '/');

  $resp = wp_remote_get($url, [
    'timeout' => 20,
    'headers' => [
      'X-API-KEY' => $api_key,
      // TackTracker endpoints often advertise multiple possible content types.
      // Sending a broader Accept header avoids some 406/empty responses.
      'Accept' => 'text/plain, application/json, text/json',
    ],
  ]);

  if (is_wp_error($resp)) return ['error' => $resp->get_error_message(), 'url' => $url];

  $code = wp_remote_retrieve_response_code($resp);
  $body = wp_remote_retrieve_body($resp);

  $dbg = null;
  if ($debug) {
    $dbg = [
      'url' => $url,
      'http_code' => $code,
      'raw_body' => is_string($body) ? substr($body, 0, 3000) : '',
    ];
  }

  if ($code < 200 || $code >= 300) {
    $out = ['error' => 'TackTracker API request failed (HTTP ' . $code . ').', 'http_code' => $code, 'body' => $body, 'url' => $url];
    if ($debug) $out['_debug'] = $dbg;
    return $out;
  }

  // Trim and strip UTF-8 BOM if present (can break json_decode)
  if (is_string($body)) {
    $body = trim($body);
    if (substr($body, 0, 3) === "\xEF\xBB\xBF") {
      $body = substr($body, 3);
    }
  }

  $json = json_decode($body, true);
  if (!is_array($json)) {
    $out = ['error' => 'TackTracker API response was not valid JSON.', 'body' => $body, 'url' => $url];
    if ($debug) $out['_debug'] = $dbg;
    return $out;
  }

  if ($debug) $json['_debug'] = $dbg;
  return $json;
}

function srp_tt_fetch_race_details($raceid) { return srp_tt_request_json('race/' . rawurlencode($raceid)); }
// Back-compat helper
function srp_tt_fetch_race($raceid) { return srp_tt_fetch_race_details($raceid); }
function srp_tt_fetch_course($raceid) { return srp_tt_request_json('raceeditor/course/' . rawurlencode($raceid)); }
function srp_tt_fetch_marks($raceid) { return srp_tt_request_json('raceeditor/marks/' . rawurlencode($raceid)); }

/**
 * Best-effort lookup: find a TackTracker race id by UTC start timestamp.
 * If the TT API doesn't support listing races, this will return null.
 */
function srp_tt_find_race_id_by_datetime(int $start_ts_utc, int $tolerance_minutes = 60): ?string {
  $date = gmdate('Y-m-d', $start_ts_utc);
  $candidates = [];

  $tries = [
    'raceeditor/races?date=' . rawurlencode($date),
    'races?date=' . rawurlencode($date),
    'raceeditor/races?after=' . rawurlencode($date . 'T00:00:00') . '&before=' . rawurlencode($date . 'T23:59:59'),
    'races?after=' . rawurlencode($date . 'T00:00:00') . '&before=' . rawurlencode($date . 'T23:59:59'),
  ];
  foreach ($tries as $ep) {
    $data = srp_tt_request_json($ep);
    if (is_array($data) && empty($data['error'])) {
      // Some endpoints wrap results
      if (isset($data['races']) && is_array($data['races'])) $data = $data['races'];
      if (!empty($data) && is_array($data)) {
        $candidates = $data;
        break;
      }
    }
  }
  if (empty($candidates) || !is_array($candidates)) return null;

  $best_id = null;
  $best_diff = null;
  foreach ($candidates as $r) {
    if (!is_array($r)) continue;
    $rid = $r['raceid'] ?? $r['race_id'] ?? $r['id'] ?? null;
    $st = $r['start'] ?? $r['starttime'] ?? $r['start_time'] ?? $r['Start'] ?? null;
    if (!$rid || !$st) continue;
    $ts = strtotime((string)$st . ' UTC');
    if (!$ts) continue;
    $diff = abs($ts - $start_ts_utc);
    if ($best_diff === null || $diff < $best_diff) {
      $best_diff = $diff;
      $best_id = (string)$rid;
    }
  }
  if ($best_id && $best_diff !== null && $best_diff <= ($tolerance_minutes * 60)) return $best_id;
  return null;
}

/**
 * List TackTracker races for a UTC date (YYYY-MM-DD). Returns array of simplified items.
 * Each item: ['raceid'=>..., 'start'=>..., 'name'=>...]
 */
function srp_tt_list_races_by_date(string $date): array {
  $date = trim((string)$date);
  if ($date === '') return [];

  // Primary: official endpoint (expects a UTC datetime, not just a date).
  // Use midday UTC so we reliably overlap any races on that date.
  $dt = $date . 'T12:00:00.000Z';
  $data = srp_tt_request_json('race/list-by-date/' . rawurlencode($dt));

  $candidates = [];
  if (is_array($data) && empty($data['error'])) {
    // API may return a plain array of races, or wrap them.
    if (isset($data['races']) && is_array($data['races'])) $data = $data['races'];
    if (is_array($data)) $candidates = $data;
  }

  // Fallback to older/alternate endpoints (some installations expose these).
  if (empty($candidates)) {
    $tries = [
      'raceeditor/races?date=' . rawurlencode($date),
      'races?date=' . rawurlencode($date),
      'raceeditor/races?after=' . rawurlencode($date . 'T00:00:00') . '&before=' . rawurlencode($date . 'T23:59:59'),
      'races?after=' . rawurlencode($date . 'T00:00:00') . '&before=' . rawurlencode($date . 'T23:59:59'),
    ];
    foreach ($tries as $ep) {
      $tmp = srp_tt_request_json($ep);
      if (is_array($tmp) && empty($tmp['error'])) {
        if (isset($tmp['races']) && is_array($tmp['races'])) $tmp = $tmp['races'];
        if (!empty($tmp) && is_array($tmp)) {
          $candidates = $tmp;
          break;
        }
      }
    }
  }
  $out = [];
  foreach ($candidates as $r) {
    if (!is_array($r)) continue;
    $rid = $r['raceid'] ?? $r['race_id'] ?? $r['id'] ?? null;
    if (!$rid) continue;
    $start = $r['start'] ?? $r['starttime'] ?? $r['start_time'] ?? $r['Start'] ?? '';
    $name = $r['name'] ?? $r['racename'] ?? $r['race_name'] ?? $r['title'] ?? '';
    $out[] = [
      'raceid' => (string)$rid,
      'start'  => (string)$start,
      'name'   => (string)$name,
    ];
  }
  return $out;
}
