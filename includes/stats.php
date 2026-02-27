<?php
if (!defined('ABSPATH')) exit;


function srp_time_to_seconds($t) {
  if ($t === null) return null;
  $t = trim((string)$t);
  if ($t === '') return null;

  // Normalize common separators:
  // - "0.28.20" => "0:28:20"
  // - "28.20"   => "28:20"
  // Keep decimal seconds like "12:34.56" handled below.
  if (strpos($t, ':') === false && preg_match('/^\d+(\.\d+)+$/', $t)) {
    $parts = explode('.', $t);
    // If 3 parts -> H:M:S, if 2 parts -> M:S
    $t = implode(':', $parts);
  }

  // Handle "H:M:S" or "M:S" (optionally with decimal seconds, e.g. 12:34.56)
  if (preg_match('/^\d+:\d+(:\d+(\.\d+)?)?$/', $t)) {
    $parts = explode(':', $t);
    if (count($parts) === 2) {
      $m = intval($parts[0]);
      $s = floatval($parts[1]);
      return $m*60 + $s;
    }
    if (count($parts) === 3) {
      $h = intval($parts[0]);
      $m = intval($parts[1]);
      $s = floatval($parts[2]);
      return $h*3600 + $m*60 + $s;
    }
  }

  // Handle plain seconds
  if (preg_match('/^\d+(\.\d+)?$/', $t)) return floatval($t);

  return null;
}


function srp_mean($vals) { if (empty($vals)) return null; return array_sum($vals)/count($vals); }

function srp_stddev_sample($vals) {
  $n=count($vals); if ($n<2) return 0.0;
  $mean=srp_mean($vals); $sum=0.0;
  foreach($vals as $v){ $d=$v-$mean; $sum+=$d*$d; }
  return sqrt($sum/($n-1));
}

function srp_filter_outliers_stddev($vals, $k=2.0) {
  $vals=array_values(array_filter($vals, fn($v)=>is_numeric($v)));
  if (count($vals)<3) return ['filtered'=>$vals,'debug'=>['k'=>$k,'mean'=>srp_mean($vals),'stddev'=>srp_stddev_sample($vals),'removed'=>0]];
  $mean=srp_mean($vals); $sd=srp_stddev_sample($vals);
  if ($sd<=0) return ['filtered'=>$vals,'debug'=>['k'=>$k,'mean'=>$mean,'stddev'=>$sd,'removed'=>0]];
  $low=$mean-($k*$sd); $high=$mean+($k*$sd);
  $filtered=[]; $removed=0;
  foreach($vals as $v){ if($v<$low||$v>$high){$removed++; continue;} $filtered[]=$v; }
  return ['filtered'=>$filtered,'debug'=>['k'=>$k,'mean'=>$mean,'stddev'=>$sd,'low'=>$low,'high'=>$high,'removed'=>$removed]];
}


/**
 * Method 1: SCT from ALL boats (boat-average corrected/lap), excluding poor performers as outliers globally (mean ± kσ).
 * corrected_per_lap = (Elapsed * 1000 / GL) / laps
 * SCT = mean(corrected_per_lap_boat_avg) over NON-outlier boats
 * Derived (per row) = elapsed_per_lap * 1000 / SCT
 */
function srp_compute_allboats(array $rows, $k=2.0){
  $debug = ['method'=>'allboats','k'=>$k];
  if (empty($rows)) return ['rows'=>$rows,'class_stats'=>[],'debug'=>$debug];

  $headers = array_keys($rows[0]);
  $class_col = null; $elapsed_col = null; $laps_col = null; $gl_col = null;
  $sailno_col = null; $helm_col = null; $boatname_col = null;

  foreach ($headers as $h) {
    if ($class_col === null && preg_match('/\bclass\b/i', $h)) $class_col = $h;
    if ($elapsed_col === null && preg_match('/\btime\b|\belapsed\b/i', $h)) $elapsed_col = $h;
    if ($laps_col === null && preg_match('/\blap/i', $h)) $laps_col = $h;
    if ($gl_col === null && preg_match('/\bgl\b|\bpy\b|handicap/i', $h)) $gl_col = $h;

    if ($sailno_col === null && preg_match('/sail\s*no|sailno|sailnos/i', $h)) $sailno_col = $h;
    if ($helm_col === null && preg_match('/\bhelm\b/i', $h)) $helm_col = $h;
    if ($boatname_col === null && preg_match('/boat\s*name|boatname/i', $h)) $boatname_col = $h;
  }
  if ($class_col === null) {
    foreach ($headers as $h) {
      if (preg_match('/boat\s*name|boatname|type|design/i', $h)) { $class_col = $h; break; }
    }
  }
  if ($class_col === null || $elapsed_col === null || $gl_col === null) {
    $debug['error'] = 'Missing required columns.';
    return ['rows'=>$rows,'class_stats'=>[],'debug'=>$debug];
  }

  $items=[];
  $boat_groups=[];

  foreach ($rows as $idx=>$r){
    // Manual exclusion always wins.
    $manual_ex = trim((string)($r['Excluded manual'] ?? $r['Manual Excluded'] ?? ''));
    if (strcasecmp($manual_ex, 'yes') === 0 || $manual_ex === '1' || $manual_ex === 'true') {
      continue;
    }
    $cls = trim((string)($r[$class_col] ?? ''));
    if ($cls==='') continue;

    $elapsed_s = srp_time_to_seconds($r[$elapsed_col] ?? '');
    if ($elapsed_s === null) continue;

    $laps = 1;
    if ($laps_col !== null){
      $laps = intval(preg_replace('/\D+/', '', (string)($r[$laps_col] ?? '1')));
      if ($laps<=0) $laps=1;
    }

    $gl_raw = trim((string)($r[$gl_col] ?? ''));
    $gl = floatval(preg_replace('/[^0-9.]/', '', $gl_raw));
    if ($gl<=0) continue;

    $elapsed_per_lap = $elapsed_s / $laps;
    $corrected_per_lap = ($elapsed_s * 1000.0 / $gl) / $laps;

    $boat_id='';
    if ($sailno_col!==null) $boat_id = trim((string)($r[$sailno_col] ?? ''));
    if ($boat_id===''){
      $h = $helm_col!==null ? trim((string)($r[$helm_col] ?? '')) : '';
      $b = $boatname_col!==null ? trim((string)($r[$boatname_col] ?? '')) : '';
      $boat_id = strtolower($h.'|'.$b);
    }
    if ($boat_id==='' || $boat_id==='|') $boat_id='row:'.$idx;

    $items[]=['idx'=>$idx,'class'=>$cls,'boat_id'=>$boat_id,'elapsed_per_lap'=>$elapsed_per_lap,'corrected_per_lap'=>$corrected_per_lap];
    if (!isset($boat_groups[$boat_id])) $boat_groups[$boat_id]=[];
    $boat_groups[$boat_id][]=$corrected_per_lap;
  }

  $boat_avgs=[];
  foreach ($boat_groups as $bid=>$vals) $boat_avgs[$bid]=srp_mean($vals);
  $vals = array_values($boat_avgs);
  if (count($vals)<2){
    $debug['error']='Not enough boats.';
    return ['rows'=>$rows,'class_stats'=>[],'debug'=>$debug];
  }

  $mean = srp_mean($vals);
  $sd = srp_stddev_sample($vals);
  $low=null; $high=null;
  $excluded_boats=[];
  if (count($vals)>=3 && $sd>0){
    $low = $mean - ($k*$sd);
    $high = $mean + ($k*$sd);
    foreach ($boat_avgs as $bid=>$avg){
      if ($avg < $low || $avg > $high){
        $excluded_boats[$bid]='Outlier (boat avg corrected/lap outside mean±kσ)';
      }
    }
  }

  $kept=[];
  foreach ($boat_avgs as $bid=>$avg){
    if (!isset($excluded_boats[$bid])) $kept[]=$avg;
  }
  if (empty($kept)){
    $debug['error']='All boats excluded.';
    $debug['mean']=$mean; $debug['sd']=$sd; $debug['low']=$low; $debug['high']=$high;
    return ['rows'=>$rows,'class_stats'=>[],'debug'=>$debug];
  }

  $sct = srp_mean($kept);
  $debug['sct']=$sct; $debug['mean']=$mean; $debug['sd']=$sd; $debug['low']=$low; $debug['high']=$high;
  $debug['boats_n']=count($boat_avgs); $debug['boats_excluded']=count($excluded_boats);

  $updated=$rows;
  $by_class=[];

  foreach ($items as $it){
    $idx=$it['idx'];
    $manual_ex = trim((string)($rows[$idx]['Excluded manual'] ?? $rows[$idx]['Manual Excluded'] ?? ''));
    if (strcasecmp($manual_ex, 'yes') === 0 || $manual_ex === '1' || $manual_ex === 'true') {
      $updated[$idx]['Excluded (All)'] = 'Yes';
      $updated[$idx]['Excluded'] = 'Yes';
      $updated[$idx]['Derived PY/GL (All)'] = '';
      $updated[$idx]['Derived PY/GL'] = '';
      $updated[$idx]['Excluded Reason (All)'] = 'Manual exclusion';
      continue;
    }
    $excluded = isset($excluded_boats[$it['boat_id']]);
    $derived = ($sct>0) ? ($it['elapsed_per_lap'] * 1000.0 / $sct) : null;

    $d_int = ($derived!==null) ? (int)round($derived) : '';
    $updated[$idx]['Derived PY/GL (All)'] = $d_int;
    $updated[$idx]['Derived PY/GL'] = $d_int;
    $ex = $excluded ? 'Yes' : 'No';
    $updated[$idx]['Excluded (All)'] = $ex;
    $updated[$idx]['Excluded'] = $ex;
    if ($excluded) $updated[$idx]['Excluded Reason (All)'] = $excluded_boats[$it['boat_id']];

    if (!$excluded && $derived!==null){
      if (!isset($by_class[$it['class']])) $by_class[$it['class']] = [];
      $by_class[$it['class']][] = $derived;
    }
  }

  $class_stats=[];
  foreach ($by_class as $cls=>$vals){
    $class_stats[$cls]=[
      'class'=>$cls,
      'derived_py'=>srp_mean($vals),
      'n'=>count($vals),
      'sct'=>$sct,
      'low'=>$low,'high'=>$high,'mean'=>$mean,'stddev'=>$sd,'k'=>$k
    ];
  }

  return ['rows'=>$updated,'class_stats'=>$class_stats,'debug'=>$debug];
}

/**
 * Method 2: SCT from BEST performer in each class (minimum boat-average corrected/lap within that class).
 * For each class, pick best boat (min corrected avg). SCT = mean(best_corrected_avg_per_class).
 * Derived (per row) = elapsed_per_lap * 1000 / SCT
 */
function srp_compute_bestofclass(array $rows){
  $debug=['method'=>'bestofclass'];
  if (empty($rows)) return ['rows'=>$rows,'class_stats'=>[],'debug'=>$debug];

  $headers = array_keys($rows[0]);
  $class_col = null; $elapsed_col = null; $laps_col = null; $gl_col = null;
  $sailno_col = null; $helm_col = null; $boatname_col = null;

  foreach ($headers as $h) {
    if ($class_col === null && preg_match('/\bclass\b/i', $h)) $class_col = $h;
    if ($elapsed_col === null && preg_match('/\btime\b|\belapsed\b/i', $h)) $elapsed_col = $h;
    if ($laps_col === null && preg_match('/\blap/i', $h)) $laps_col = $h;
    if ($gl_col === null && preg_match('/\bgl\b|\bpy\b|handicap/i', $h)) $gl_col = $h;

    if ($sailno_col === null && preg_match('/sail\s*no|sailno|sailnos/i', $h)) $sailno_col = $h;
    if ($helm_col === null && preg_match('/\bhelm\b/i', $h)) $helm_col = $h;
    if ($boatname_col === null && preg_match('/boat\s*name|boatname/i', $h)) $boatname_col = $h;
  }
  if ($class_col === null) {
    foreach ($headers as $h) {
      if (preg_match('/boat\s*name|boatname|type|design/i', $h)) { $class_col = $h; break; }
    }
  }
  if ($class_col === null || $elapsed_col === null || $gl_col === null) {
    $debug['error']='Missing required columns.';
    return ['rows'=>$rows,'class_stats'=>[],'debug'=>$debug];
  }

  $items=[];
  $boat_groups=[]; // class => boat_id => list corrected_per_lap

  foreach ($rows as $idx=>$r){
    // Manual exclusion always wins.
    $manual_ex = trim((string)($r['Excluded manual'] ?? $r['Manual Excluded'] ?? ''));
    if (strcasecmp($manual_ex, 'yes') === 0 || $manual_ex === '1' || $manual_ex === 'true') {
      continue;
    }
    $cls = trim((string)($r[$class_col] ?? ''));
    if ($cls==='') continue;

    $elapsed_s = srp_time_to_seconds($r[$elapsed_col] ?? '');
    if ($elapsed_s === null) continue;

    $laps=1;
    if ($laps_col!==null){
      $laps=intval(preg_replace('/\D+/', '', (string)($r[$laps_col] ?? '1')));
      if ($laps<=0) $laps=1;
    }

    $gl_raw = trim((string)($r[$gl_col] ?? ''));
    $gl = floatval(preg_replace('/[^0-9.]/', '', $gl_raw));
    if ($gl<=0) continue;

    $elapsed_per_lap = $elapsed_s / $laps;
    $corrected_per_lap = ($elapsed_s * 1000.0 / $gl) / $laps;

    $boat_id='';
    if ($sailno_col!==null) $boat_id = trim((string)($r[$sailno_col] ?? ''));
    if ($boat_id===''){
      $h = $helm_col!==null ? trim((string)($r[$helm_col] ?? '')) : '';
      $b = $boatname_col!==null ? trim((string)($r[$boatname_col] ?? '')) : '';
      $boat_id = strtolower($h.'|'.$b);
    }
    if ($boat_id==='' || $boat_id==='|') $boat_id=$cls.':row:'.$idx;

    $items[]=['idx'=>$idx,'class'=>$cls,'elapsed_per_lap'=>$elapsed_per_lap,'boat_id'=>$boat_id];

    if (!isset($boat_groups[$cls])) $boat_groups[$cls]=[];
    if (!isset($boat_groups[$cls][$boat_id])) $boat_groups[$cls][$boat_id]=[];
    $boat_groups[$cls][$boat_id][]=$corrected_per_lap;
  }

  $best_corr=[]; $best_boat=[];
  foreach ($boat_groups as $cls=>$boats){
    $best_val=null; $best_id=null;
    foreach ($boats as $bid=>$vals){
      $avg = srp_mean($vals);
      if ($best_val===null || $avg < $best_val){
        $best_val=$avg; $best_id=$bid;
      }
    }
    if ($best_val!==null){
      $best_corr[$cls]=$best_val;
      $best_boat[$cls]=$best_id;
    }
  }

  if (count($best_corr)<2){
    $debug['error']='Not enough classes with data.';
    return ['rows'=>$rows,'class_stats'=>[],'debug'=>$debug];
  }

  $sct = srp_mean(array_values($best_corr));
  $debug['sct']=$sct;
  $debug['classes_n']=count($best_corr);

  $updated=$rows;
  $by_class=[];

  foreach ($items as $it){
    $idx=$it['idx'];
    $manual_ex = trim((string)($rows[$idx]['Excluded manual'] ?? $rows[$idx]['Manual Excluded'] ?? ''));
    if (strcasecmp($manual_ex, 'yes') === 0 || $manual_ex === '1' || $manual_ex === 'true') {
      $updated[$idx]['Derived PY/GL (Best)'] = '';
      $updated[$idx]['Excluded (Best)'] = 'Yes';
      $updated[$idx]['Excluded Reason (Best)'] = 'Manual exclusion';
      continue;
    }
    $derived = ($sct>0) ? ($it['elapsed_per_lap'] * 1000.0 / $sct) : null;
    $d_int = ($derived!==null) ? (int)round($derived) : '';
    $updated[$idx]['Derived PY/GL (Best)'] = $d_int;
    $updated[$idx]['Derived PY/GL'] = $d_int;
    $updated[$idx]['Excluded (Best)'] = 'No';
    $updated[$idx]['Excluded'] = 'No';
    if ($derived!==null){
      if (!isset($by_class[$it['class']])) $by_class[$it['class']] = [];
      $by_class[$it['class']][] = $derived;
    }
  }

  $class_stats=[];
  foreach ($by_class as $cls=>$vals){
    $class_stats[$cls]=[
      'class'=>$cls,
      'derived_py'=>srp_mean($vals),
      'n'=>count($vals),
      'sct'=>$sct,
      'best_corrected_lap_s'=>($best_corr[$cls] ?? null),
      'best_boat_id'=>($best_boat[$cls] ?? null)
    ];
  }

  return ['rows'=>$updated,'class_stats'=>$class_stats,'debug'=>$debug];
}

/** Run both methods */
function srp_compute_dual(array $rows, $k=2.0){
  return [
    'all'  => srp_compute_allboats($rows,$k),
    'best' => srp_compute_bestofclass($rows),
  ];
}

