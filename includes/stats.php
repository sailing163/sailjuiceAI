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
      $updated[$idx]['Excluded (RYA)'] = 'Yes';
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
    // best performer per class, then apply same SD outlier filter on the subset
    'best' => srp_compute_bestofclass_sd($rows,$k),
    // RYA median method (top 66%, then within 110% of median)
    'rya'  => srp_compute_rya_median($rows),
  ];
}



// Model 2: choose best corrected-per-lap boat per class, then apply SD outlier filter via allboats method
function srp_compute_bestofclass_sd(array $rows, $k=2.0){
  // First, get best-of-class single boats list from existing bestofclass method
  $best = srp_compute_bestofclass($rows);
  $subset = $best['rows'] ?? $rows;
  return srp_compute_allboats($subset, $k);
}

// Model 3: RYA median methodology:
// - compute corrected-per-lap using provided PY/GL
// - take top 66% (fastest) corrected-per-lap to define median
// - keep results within 110% of that median, then compute SCT + derived PY/GL like allboats
function srp_compute_rya_median(array $rows){
  $debug=['method'=>'rya_median'];
  if (empty($rows)) return ['rows'=>$rows,'class_stats'=>[],'debug'=>$debug];

  $headers = array_keys($rows[0]);
  $class_col=null; $elapsed_col=null; $laps_col=null; $gl_col=null;
  foreach ($headers as $h) {
    if ($class_col===null && preg_match('/\bclass\b/i',$h)) $class_col=$h;
    if ($elapsed_col===null && preg_match('/\btime\b|\belapsed\b/i',$h)) $elapsed_col=$h;
    if ($laps_col===null && preg_match('/\blap/i',$h)) $laps_col=$h;
    if ($gl_col===null && preg_match('/\bgl\b|\bpy\b|handicap/i',$h)) $gl_col=$h;
  }
  if ($class_col===null || $elapsed_col===null || $gl_col===null){
    $debug['error']='Missing required columns.';
    return ['rows'=>$rows,'class_stats'=>[],'debug'=>$debug];
  }
  $items=[];
  foreach ($rows as $idx=>$r){
    $manual_ex = trim((string)($r['Excluded manual'] ?? $r['Manual Excluded'] ?? ''));
    if (strcasecmp($manual_ex,'yes')===0) continue;
    $elapsed = floatval($r[$elapsed_col] ?? 0);
    $laps = $laps_col ? max(1.0, floatval($r[$laps_col] ?? 1)) : 1.0;
    $gl = floatval($r[$gl_col] ?? 0);
    if ($elapsed<=0 || $gl<=0) continue;
    $corr_per_lap = ($elapsed * 1000.0 / $gl) / $laps;
    $items[] = ['idx'=>$idx,'corr'=>$corr_per_lap];
  }
  if (empty($items)) return ['rows'=>$rows,'class_stats'=>[],'debug'=>$debug];

  usort($items, fn($a,$b)=> $a['corr'] <=> $b['corr']);
  $keep_n = max(1, (int)floor(count($items)*0.66));
  $top = array_slice($items,0,$keep_n);
  $corrs = array_column($top,'corr');
  sort($corrs);
  $median = $corrs[(int)floor((count($corrs)-1)/2)];
  $limit = $median*1.10;
  $debug['median']=$median; $debug['limit']=$limit; $debug['top_n']=$keep_n;

  // mark exclusions for rows outside limit (but keep manual excludes)
  $rows2=$rows;
  foreach ($items as $it){
    $idx=$it['idx'];
    if ($it['corr'] > $limit){
      $rows2[$idx]['Excluded (All)']='Yes';
      $rows2[$idx]['Exclusion Reason (All)']='>110% median';
    }
  }
  // Reuse allboats to compute SCT/derived on filtered set (it will respect Excluded flags)
  return srp_compute_allboats($rows2, 2.0);
}

function srp_compute_rya_median_v2(array $rows){
  $debug=['method'=>'rya_median_v2'];
  if (empty($rows)) return ['rows'=>$rows,'class_stats'=>[],'debug'=>$debug];
  $headers=array_keys($rows[0]);
  $class_col=null; $elapsed_col=null; $laps_col=null; $gl_col=null;
  foreach ($headers as $h){
    if ($class_col===null && preg_match('/\bclass\b/i',$h)) $class_col=$h;
    if ($elapsed_col===null && preg_match('/\btime\b|\belapsed\b/i',$h)) $elapsed_col=$h;
    if ($laps_col===null && preg_match('/\blap/i',$h)) $laps_col=$h;
    if ($gl_col===null && preg_match('/\bgl\b|\bpy\b|handicap/i',$h)) $gl_col=$h;
  }
  if ($class_col===null || $elapsed_col===null || $gl_col===null){
    $debug['error']='Missing required columns.';
    return ['rows'=>$rows,'class_stats'=>[],'debug'=>$debug];
  }
  $items=[];
  foreach ($rows as $idx=>$r){
    $manual_ex = trim((string)($r['Excluded manual'] ?? $r['Manual Excluded'] ?? ''));
    if (strcasecmp($manual_ex,'yes')===0) continue;
    $elapsed=floatval($r[$elapsed_col] ?? 0);
    $laps=$laps_col ? max(1.0, floatval($r[$laps_col] ?? 1)) : 1.0;
    $gl=floatval($r[$gl_col] ?? 0);
    if ($elapsed<=0 || $gl<=0) continue;
    $corr_per_lap = ($elapsed * 1000.0 / $gl) / $laps;
    $items[]=['idx'=>$idx,'corr'=>$corr_per_lap,'gl'=>$gl,'elapsed'=>$elapsed,'laps'=>$laps,'class'=>(string)$r[$class_col]];
  }
  if (empty($items)) return ['rows'=>$rows,'class_stats'=>[],'debug'=>$debug];
  usort($items, fn($a,$b)=> $a['corr'] <=> $b['corr']);
  $keep_n=max(1,(int)floor(count($items)*0.66));
  $top=array_slice($items,0,$keep_n);
  $corrs=array_column($top,'corr');
  sort($corrs);
  $median=$corrs[(int)floor((count($corrs)-1)/2)];
  $limit=$median*1.10;
  $debug['median']=$median; $debug['limit']=$limit; $debug['top_n']=$keep_n;

  $rows2=$rows;
  foreach ($items as $it){
    if ($it['corr'] > $limit){
      $rows2[$it['idx']]['Excluded (All)']='Yes';
      $rows2[$it['idx']]['Exclusion Reason (All)']='>110% median';
    }
  }

  $included=[];
  foreach ($items as $it){ if ($it['corr'] <= $limit) $included[]=$it; }
  if (empty($included)) return ['rows'=>$rows2,'class_stats'=>[],'debug'=>$debug];

  $sct = array_sum(array_column($included,'corr'))/count($included);
  $debug['sct']=$sct;

  $class_buckets=[];
  foreach ($included as $it){ $class_buckets[$it['class']][]=$it; }
  $class_stats=[];
  foreach ($class_buckets as $class=>$arr){
    $vals=[];
    foreach ($arr as $it){
      $elapsed_per_lap = $it['elapsed']/$it['laps'];
      $vals[] = 1000.0 * ($elapsed_per_lap / $sct);
    }
    sort($vals);
    $derived = $vals[(int)floor((count($vals)-1)/2)];
    $class_stats[$class]=['derived_py'=>$derived,'n'=>count($arr)];
  }
  foreach ($rows2 as $idx=>$r){
    $class=(string)($r[$class_col] ?? '');
    if ($class!=='' && isset($class_stats[$class]['derived_py'])){
      $rows2[$idx]['Derived PY/GL (All)'] = (int)round($class_stats[$class]['derived_py']);
    }
  }
  return ['rows'=>$rows2,'class_stats'=>$class_stats,'debug'=>$debug,'sct'=>$sct];
}

function srp_compute_dual_v2(array $rows, $k=2.0){
  return [
    'all'  => srp_compute_allboats($rows,$k),
    'best' => srp_compute_bestofclass_sd($rows,$k),
    'rya'  => srp_compute_rya_median_v2($rows),
  ];
}
