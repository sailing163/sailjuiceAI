<?php
// Minimal XLSX reader (first worksheet only). No external deps.
// Returns: array of associative arrays using row-1 header values.

if (!defined('ABSPATH')) exit;

function srp_xlsx_read_rows(string $path): array {
  if (!file_exists($path)) return [];

  $zip = new ZipArchive();
  if ($zip->open($path) !== true) return [];

  $shared = [];
  $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
  if ($sharedXml) {
    $sx = @simplexml_load_string($sharedXml);
    if ($sx && isset($sx->si)) {
      foreach ($sx->si as $si) {
        // Strings can be in <t> or multiple <r><t>
        $text = '';
        if (isset($si->t)) {
          $text = (string)$si->t;
        } elseif (isset($si->r)) {
          foreach ($si->r as $r) {
            if (isset($r->t)) $text .= (string)$r->t;
          }
        }
        $shared[] = $text;
      }
    }
  }

  // First worksheet (sheet1.xml)
  $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
  if (!$sheetXml) {
    $zip->close();
    return [];
  }

  $sheet = @simplexml_load_string($sheetXml);
  if (!$sheet || !isset($sheet->sheetData->row)) {
    $zip->close();
    return [];
  }

  // Convert Excel cell ref like "C12" to 1-based column index.
  $colIndex = function(string $cellRef): int {
    if (!preg_match('/^([A-Z]+)(\d+)$/i', $cellRef, $m)) return 0;
    $letters = strtoupper($m[1]);
    $n = 0;
    for ($i=0; $i<strlen($letters); $i++) {
      $n = $n*26 + (ord($letters[$i]) - 64);
    }
    return $n;
  };

  $rows = [];
  foreach ($sheet->sheetData->row as $row) {
    $cells = [];
    foreach ($row->c as $c) {
      $ref = (string)$c['r'];
      $idx = $colIndex($ref);
      if ($idx <= 0) continue;
      $type = (string)$c['t'];
      $v = isset($c->v) ? (string)$c->v : '';
      if ($type === 's') {
        $v = $shared[(int)$v] ?? '';
      }
      $cells[$idx] = $v;
    }
    if (!empty($cells)) {
      ksort($cells);
      $rows[] = $cells;
    }
  }

  $zip->close();
  if (empty($rows)) return [];

  // Header row
  $headerRow = $rows[0];
  $headers = [];
  $maxCol = max(array_keys($headerRow));
  for ($c=1; $c<=$maxCol; $c++) {
    $h = trim((string)($headerRow[$c] ?? ''));
    if ($h === '') $h = 'col_' . $c;
    $headers[$c] = $h;
  }

  $out = [];
  for ($r=1; $r<count($rows); $r++) {
    $rowCells = $rows[$r];
    $assoc = [];
    foreach ($headers as $c => $h) {
      $assoc[$h] = isset($rowCells[$c]) ? trim((string)$rowCells[$c]) : '';
    }
    // skip empty rows
    $nonEmpty = false;
    foreach ($assoc as $v) { if ($v !== '') { $nonEmpty = true; break; } }
    if ($nonEmpty) $out[] = $assoc;
  }
  return $out;
}
