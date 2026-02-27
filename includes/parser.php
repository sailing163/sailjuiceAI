<?php
if (!defined('ABSPATH')) exit;

/**
 * Parse pasted HTML results and return rows suitable for a preview table.
 *
 * @param string $html
 * @return array{rows: array<int, array<string,string>>, debug: array<string,mixed>, error?: string}
 */
function srp_parse_results_html($html) {
  $out = [
    'rows' => [],
    'debug' => [
      'tables_found' => 0,
      'chosen_table_index' => null,
      'chosen_table_score' => null,
      'header_row_index' => null,
      'raw_matrix' => [],
    ],
  ];

  // Wrap fragments so DOMDocument can load them.
  $wrapped = $html;
  if (stripos($html, '<html') === false) {
    $wrapped = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>';
  }

  libxml_use_internal_errors(true);
  $dom = new DOMDocument();
  $loaded = $dom->loadHTML($wrapped, LIBXML_NOWARNING | LIBXML_NOERROR);
  libxml_clear_errors();

  if (!$loaded) {
    $out['error'] = 'Could not parse HTML.';
    return $out;
  }

  $xpath = new DOMXPath($dom);
  $tables = $xpath->query('//table');
  $out['debug']['tables_found'] = $tables ? $tables->length : 0;

  if (!$tables || $tables->length === 0) {
    $out['error'] = 'No <table> elements found in the pasted HTML.';
    return $out;
  }

  // Score tables and choose best candidate.
  $bestIdx = null;
  $bestScore = -1;
  $bestMatrix = [];

  for ($i = 0; $i < $tables->length; $i++) {
    $t = $tables->item($i);
    $matrix = srp_table_to_matrix($t);
    $rowCount = count($matrix);
    $colCount = 0;
    foreach ($matrix as $r) $colCount = max($colCount, count($r));

    if ($rowCount < 2 || $colCount < 4) continue;

    // Score: area + bonus for header keywords present in first few rows.
    $area = $rowCount * $colCount;
    $bonus = 0;
    $sampleRows = array_slice($matrix, 0, min(5, $rowCount));
    $sampleText = strtolower(implode(' ', array_map(function($r){ return implode(' ', $r); }, $sampleRows)));
    $keywords = ['helm','skipper','sail','sail no','sailno','class','club','rank','place','total','points','nett','time','elapsed'];
    foreach ($keywords as $k) {
      if (strpos($sampleText, $k) !== false) $bonus += 50;
    }

    $score = $area + $bonus;

    if ($score > $bestScore) {
      $bestScore = $score;
      $bestIdx = $i;
      $bestMatrix = $matrix;
    }
  }

  // If nothing met thresholds, fallback to the largest by area anyway.
  if ($bestIdx === null) {
    for ($i = 0; $i < $tables->length; $i++) {
      $matrix = srp_table_to_matrix($tables->item($i));
      $rowCount = count($matrix);
      $colCount = 0;
      foreach ($matrix as $r) $colCount = max($colCount, count($r));
      $score = $rowCount * $colCount;
      if ($score > $bestScore) {
        $bestScore = $score;
        $bestIdx = $i;
        $bestMatrix = $matrix;
      }
    }
  }

  $out['debug']['chosen_table_index'] = $bestIdx;
  $out['debug']['chosen_table_score'] = $bestScore;
  $out['debug']['raw_matrix'] = $bestMatrix;

  // Detect header row
  $headerIdx = srp_detect_header_row($bestMatrix);
  $out['debug']['header_row_index'] = $headerIdx;

  // Build headers
  $headers = [];
  if ($headerIdx !== null && isset($bestMatrix[$headerIdx])) {
    $headers = srp_normalize_headers($bestMatrix[$headerIdx]);
  } else {
    // Create generic headers
    $maxCols = 0;
    foreach ($bestMatrix as $r) $maxCols = max($maxCols, count($r));
    for ($c=0; $c<$maxCols; $c++) $headers[] = 'Col ' . ($c+1);
    $headerIdx = -1;
  }

  // Convert following rows into associative arrays
  $rows = [];
  for ($r = $headerIdx + 1; $r < count($bestMatrix); $r++) {
    $cells = $bestMatrix[$r];
    // Skip empty rows
    $nonEmpty = false;
    foreach ($cells as $v) { if (trim($v) !== '') { $nonEmpty = true; break; } }
    if (!$nonEmpty) continue;

    $assoc = [];
    for ($c = 0; $c < count($headers); $c++) {
      $val = isset($cells[$c]) ? $cells[$c] : '';
      $assoc[$headers[$c]] = srp_clean_cell($val);
    }

    // Remove rows that look like repeated headers or separators
    if (srp_row_looks_like_header_repeat($assoc, $headers)) continue;

    $rows[] = $assoc;
    if (count($rows) >= 500) break; // safety limit
  }

  // If headers have "Time" columns, normalize time formats in those cells
  if (!empty($rows)) {
    $timeCols = [];
    foreach ($headers as $h) {
      if (preg_match('/\btime\b|\belapsed\b/i', $h)) $timeCols[] = $h;
    }
    if (!empty($timeCols)) {
      foreach ($rows as &$row) {
        foreach ($timeCols as $tc) {
          if (!empty($row[$tc])) {
            $row[$tc] = srp_normalize_time_string($row[$tc]);
          }
        }
      }
      unset($row);
    }
  }

  $out['rows'] = $rows;
  return $out;
}

/**
 * Convert a DOM <table> to a 2D matrix of cell text.
 */
function srp_table_to_matrix(DOMNode $table) {
  $rows = [];
  $xpath = new DOMXPath($table->ownerDocument);
  $trNodes = $xpath->query('.//tr', $table);
  if (!$trNodes) return $rows;

  foreach ($trNodes as $tr) {
    $cellNodes = $xpath->query('./th|./td', $tr);
    if (!$cellNodes) continue;

    $row = [];
    foreach ($cellNodes as $cell) {
      $txt = srp_node_text($cell);
      $row[] = $txt;
    }
    // Trim trailing empties
    while (count($row) > 0 && trim($row[count($row)-1]) === '') array_pop($row);
    $rows[] = $row;
  }

  return $rows;
}

function srp_node_text(DOMNode $node) {
  // Convert <br> to spaces/newlines by cloning HTML and stripping tags
  $html = $node->ownerDocument->saveHTML($node);
  $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
  $text = wp_strip_all_tags($html);
  $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  // Collapse whitespace but keep newlines into spaces
  $text = preg_replace("/[ \t\r\f\v]+/u", " ", $text);
  $text = preg_replace("/\n+/u", " ", $text);
  return trim($text);
}

function srp_detect_header_row(array $matrix) {
  $keywords = ['helm','skipper','sail','sail no','sailno','class','club','rank','place','total','points','nett','time','elapsed'];
  $maxCheck = min(8, count($matrix));
  $bestIdx = null;
  $bestScore = -1;

  for ($i=0; $i<$maxCheck; $i++) {
    $row = $matrix[$i];
    if (count($row) < 2) continue;
    $text = strtolower(implode(' ', $row));

    $score = 0;
    foreach ($keywords as $k) {
      if (strpos($text, $k) !== false) $score += 5;
    }
    // Penalize numeric-heavy rows (likely data)
    $numericCells = 0;
    foreach ($row as $cell) {
      $cellTrim = trim($cell);
      if ($cellTrim !== '' && preg_match('/^[\d\W]+$/u', $cellTrim)) $numericCells++;
    }
    $score -= $numericCells;

    // Prefer rows with more distinct non-empty strings
    $nonEmpty = array_filter($row, function($v){ return trim($v) !== ''; });
    $score += min(5, count($nonEmpty));

    if ($score > $bestScore) {
      $bestScore = $score;
      $bestIdx = $i;
    }
  }

  if ($bestIdx !== null && $bestScore >= 4) return $bestIdx;

  return null;
}

function srp_normalize_headers(array $headerRow) {
  $headers = [];
  $seen = [];
  foreach ($headerRow as $i => $h) {
    $h = srp_clean_cell($h);
    if ($h === '') $h = 'Col ' . ($i+1);

    // Normalize a few common variants
    $map = [
      'sailno' => 'Sail No',
      'sail no' => 'Sail No',
      'sail number' => 'Sail No',
      'helm' => 'Helm',
      'skipper' => 'Helm',
      'class' => 'Class',
      'club' => 'Club',
      'rank' => 'Rank',
      'place' => 'Rank',
      'total' => 'Total',
      'points' => 'Points',
      'nett' => 'Nett',
      'time' => 'Time',
      'elapsed' => 'Elapsed',
    ];
    $key = strtolower(trim($h));
    if (isset($map[$key])) $h = $map[$key];

    // De-dupe
    $base = $h;
    $n = 2;
    while (isset($seen[strtolower($h)])) {
      $h = $base . ' ' . $n;
      $n++;
    }
    $seen[strtolower($h)] = true;
    $headers[] = $h;
  }
  return $headers;
}

function srp_clean_cell($v) {
  $v = trim((string)$v);
  $v = preg_replace("/\s+/u", " ", $v);
  return $v;
}

function srp_row_looks_like_header_repeat(array $assoc, array $headers) {
  $joined = strtolower(implode(' ', array_values($assoc)));
  $hits = 0;
  foreach ($headers as $h) {
    $hh = strtolower(preg_replace('/\s+/', ' ', trim($h)));
    if ($hh !== '' && strpos($joined, $hh) !== false) $hits++;
  }
  return $hits >= max(3, (int)floor(count($headers) / 2));
}

/**
 * Normalize time-like strings to H:MM:SS when possible.
 */
function srp_normalize_time_string($s) {
  $s = trim((string)$s);
  if ($s === '') return $s;

  // Extract first time-ish token if mixed content
  if (preg_match('/(\d{1,3}:\d{2}:\d{2}|\d{1,3}:\d{2})(?!\d)/', $s, $m)) {
    $s = $m[1];
  }

  $parts = explode(':', $s);
  if (count($parts) < 2 || count($parts) > 3) return $s;

  $nums = array_map('intval', $parts);
  if (count($nums) === 2) {
    // MM:SS -> convert to H:MM:SS
    $mm = $nums[0]; $ss = $nums[1];
    if ($ss >= 60) return $s;
    $hh = intdiv($mm, 60);
    $mm2 = $mm % 60;
    return sprintf('%d:%02d:%02d', $hh, $mm2, $ss);
  } else {
    $hh = $nums[0]; $mm = $nums[1]; $ss = $nums[2];
    if ($mm >= 60 || $ss >= 60) return $s;
    return sprintf('%d:%02d:%02d', $hh, $mm, $ss);
  }
}
