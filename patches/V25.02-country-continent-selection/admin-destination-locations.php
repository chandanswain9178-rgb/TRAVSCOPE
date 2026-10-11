<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/location-master-functions.php';
if(empty($_SESSION['admin_id'])){header('Location: '.BASE_URL.'login.php');exit;}
$adminId=(int)$_SESSION['admin_id'];
$st=$pdo->prepare("SELECT id,name,email,role,status FROM admins WHERE id=? LIMIT 1");$st->execute([$adminId]);$admin=$st->fetch(PDO::FETCH_ASSOC);
if(!$admin||($admin['status']??'')!=='active'){unset($_SESSION['admin_id']);header('Location: '.BASE_URL.'login.php');exit;}
function dlE($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$schemaError='';try{$r=tsLocationEnsureSchema($pdo);if(empty($r['ready']))$schemaError=(string)($r['message']??'Destination City Master setup failed.');}catch(Throwable $e){$schemaError=$e->getMessage();}
if(empty($_SESSION['destination_location_csrf']))$_SESSION['destination_location_csrf']=bin2hex(random_bytes(32));$csrf=(string)$_SESSION['destination_location_csrf'];
$success=(string)($_SESSION['destination_location_success']??'');$error=(string)($_SESSION['destination_location_error']??'');unset($_SESSION['destination_location_success'],$_SESSION['destination_location_error']);
$notice=(string)($_SESSION['destination_location_notice']??'');$syncOutcome=(string)($_SESSION['destination_location_sync_outcome']??'');unset($_SESSION['destination_location_notice'],$_SESSION['destination_location_sync_outcome']);
$selected=max(0,(int)($_GET['destination_id']??$_POST['destination_id']??0));
$filterQ=trim((string)($_GET['q']??$_POST['return_q']??''));
$filterStatus=(string)($_GET['status']??$_POST['return_status']??'all');if(!in_array($filterStatus,['all','active','inactive'],true))$filterStatus='all';
$filterSource=strtolower(trim((string)($_GET['source']??$_POST['return_source']??'all')));if(!preg_match('/^[a-z0-9_]{1,30}$/',$filterSource))$filterSource='all';
$filterState=['q'=>$filterQ,'status'=>$filterStatus,'source'=>$filterSource];
if($_SERVER['REQUEST_METHOD']==='POST'){
 $destinationId=max(0,(int)($_POST['destination_id']??0));
 try{
  if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Security verification failed.');
  $action=(string)($_POST['action']??'');$postDestination=tsLocationDestination($pdo,$destinationId);
  if(!$postDestination||($postDestination['status']??'')!=='active')throw new RuntimeException('Select an active destination. Activate this destination in Destination Master before changing its locations.');
  if($action==='add_location'){
   $name=tsLocationNormalize((string)($_POST['location_name']??''));if($name==='')throw new RuntimeException('Enter the city / location name.');
   if(mb_strlen($name,'UTF-8')>150)throw new RuntimeException('City / location name must be 150 characters or fewer.');
   $existing=$pdo->prepare("SELECT id,status FROM destination_locations WHERE destination_id=? AND LOWER(TRIM(location_name))=LOWER(TRIM(?)) LIMIT 1");$existing->execute([$destinationId,$name]);$existingRow=$existing->fetch(PDO::FETCH_ASSOC);
   if($existingRow){$_SESSION['destination_location_notice']=($existingRow['status']??'')==='inactive'?'This location is already saved and inactive. Use Activate on its row to make it available.':'This location is already saved for the selected destination.';}
   else{$locationId=tsLocationUpsert($pdo,$destinationId,$name,'manual',$adminId);if($locationId<=0)throw new RuntimeException('Location could not be saved. Try again.');$_SESSION['destination_location_success']='City / location added.';}
  }elseif($action==='sync_geo'){
   $r=tsLocationSyncGeoCities($pdo,$destinationId,$adminId,true);$outcome=(string)($r['outcome']??((int)($r['synced']??0)>0?'added':'unavailable'));
   $labels=['added'=>'Added','up_to_date'=>'Up to date','unavailable'=>'Unavailable','failed'=>'Failed'];$label=$labels[$outcome]??'Unavailable';
   $sources=['geo_api'=>'Geographic service','offline_seed'=>'Offline city list','local'=>'Saved locations','unavailable'=>'Location directory'];
   $message=$label.' · '.($sources[(string)($r['source']??'')]??'City service').': '.(string)($r['message']??'Add a city / location manually.');
   $_SESSION['destination_location_sync_outcome']=$outcome;
   if($outcome==='failed')$_SESSION['destination_location_error']=$message;
   elseif($outcome==='added')$_SESSION['destination_location_success']=$message;
   else $_SESSION['destination_location_notice']=$message;
  }elseif($action==='set_status'){
   $locationId=max(0,(int)($_POST['location_id']??0));$desired=(string)($_POST['desired_status']??'');tsLocationSetStatus($pdo,$destinationId,$locationId,$desired,$adminId);
   $_SESSION['destination_location_success']=$desired==='active'?'Location activated.':'Location deactivated.';
  }else throw new RuntimeException('Choose a valid location action.');
 }catch(Throwable $e){$_SESSION['destination_location_error']=$e->getMessage();}
 header('Location: '.BASE_URL.'admin-destination-locations.php?'.http_build_query(['destination_id'=>$destinationId]+$filterState));exit;
}
$destinations=[];if(tsLocationTableExists($pdo,'destinations'))$destinations=$pdo->query("SELECT id,name,country,status FROM destinations ORDER BY country,name")->fetchAll(PDO::FETCH_ASSOC)?:[];
if(!isset($_GET['destination_id']) && $selected<=0 && $destinations)$selected=(int)$destinations[0]['id'];
$destination=$selected>0?tsLocationDestination($pdo,$selected):null;$canManage=$destination&&($destination['status']??'')==='active';
$locations=$destination?tsLocationAdminList($pdo,$selected):[];
$totalLocations=0;if(tsLocationTableExists($pdo,'destination_locations'))$totalLocations=(int)$pdo->query("SELECT COUNT(*) FROM destination_locations dl JOIN destinations d ON d.id=dl.destination_id")->fetchColumn();
$reloadUrl='admin-destination-locations.php?'.http_build_query(['destination_id'=>$selected]+$filterState);
?>
<?php
$selectedActive = 0;
foreach($locations as $loc){if(strtolower((string)($loc['status']??''))==='active') $selectedActive++;}
$selectedInactive = count($locations)-$selectedActive;
$locationMatches=static function(array $row) use($filterQ,$filterStatus,$filterSource):bool {
 return ($filterQ===''||mb_stripos((string)$row['location_name'],$filterQ,0,'UTF-8')!==false)
  &&($filterStatus==='all'||strtolower((string)$row['status'])===$filterStatus)
  &&($filterSource==='all'||strtolower((string)$row['source'])===$filterSource);
};
$visibleLocations=count(array_filter($locations,$locationMatches));
$locationSourceLabels = [
 'geo_api'=>'Geo Service', 'manual'=>'Manual', 'existing_data'=>'Existing Data',
 'supplier_hotel_option'=>'Supplier Hotel', 'supplier_vehicle_option'=>'Supplier Vehicle',
 'supplier_rate_card'=>'Supplier Rate', 'fallback'=>'Geo Fallback', 'offline_seed'=>'Offline City List'
];
?>
<!doctype html>
<html lang="en">
<head>
 <meta charset="utf-8">
 <meta name="viewport" content="width=device-width,initial-scale=1">
 <title>Cities &amp; Locations | TRAVSCOPE Administration</title>
 <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
 <script>document.documentElement.classList.add('ts-pro-preload');</script>
 <link rel="stylesheet" href="assets/travscope-pro-admin.css?v=20260916-v9">
 <style id="travscope-v2452-compact-location-master">
 :root{--lc-ink:#102a49;--lc-muted:#64758c;--lc-line:#dce6f0;--lc-blue:#0a72ed;--lc-sky:#f4f9ff}
 .tsloc,.tsloc *{box-sizing:border-box}
 .tsloc{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--lc-ink);font-size:12px;line-height:1.45}
 .tsloc .loc-header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:13px 15px;margin-bottom:9px;border:1px solid var(--lc-line);border-left:4px solid var(--lc-blue);border-radius:12px;background:linear-gradient(115deg,#fff,#f5faff);box-shadow:0 5px 18px rgba(21,52,85,.045)}
 .tsloc .loc-heading{display:flex;align-items:center;gap:10px 15px;flex-wrap:wrap;min-width:0}
 .tsloc h1{font-size:20px;line-height:1.2;letter-spacing:-.45px;font-weight:800;margin:0;color:#142b46}
 .tsloc .loc-subtitle{font-size:10px;color:var(--lc-muted);margin:4px 0 0}
 .tsloc .loc-metrics{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
 .tsloc .loc-metric{display:inline-flex;align-items:center;gap:5px;border:1px solid #deebf7;border-radius:7px;padding:5px 8px;background:#fff;color:#516882;font-size:10px;font-weight:650;white-space:nowrap}
 .tsloc .loc-metric strong{font-size:13px;line-height:1;color:#17365c;font-variant-numeric:tabular-nums}
 .tsloc .loc-metric i{color:#2973d2}
 .tsloc .loc-metric.green{background:#eaf9f1;border-color:#ccebdc;color:#08754d}.tsloc .loc-metric.green i,.tsloc .loc-metric.green strong{color:#08754d}
 .tsloc .loc-metric.orange{background:#fff8eb;border-color:#f5e3c4;color:#985e16}.tsloc .loc-metric.orange i,.tsloc .loc-metric.orange strong{color:#985e16}
 .tsloc .loc-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
 .tsloc .loc-btn{border:1px solid #d6e3f1;border-radius:7px;background:#fff;color:#214365;min-height:32px;padding:0 11px;font-family:inherit;font-size:11px;line-height:1.1;font-weight:750;text-decoration:none;display:inline-flex;justify-content:center;align-items:center;gap:6px;cursor:pointer;white-space:nowrap;transition:background .15s,border-color .15s,box-shadow .15s}
 .tsloc .loc-btn:hover{background:#eff6ff;border-color:#9ec4f3}.tsloc .loc-btn:focus-visible,.tsloc .loc-input:focus-visible,.tsloc .loc-select:focus-visible{outline:2px solid #3888ef;outline-offset:2px}
 .tsloc .loc-btn.blue{background:var(--lc-blue);border-color:var(--lc-blue);color:#fff}.tsloc .loc-btn.blue:hover{background:#085fc8}
 .tsloc .loc-btn.green-btn{background:#0b986c;border-color:#0b986c;color:#fff}.tsloc .loc-btn.green-btn:hover{background:#087a56}
 .tsloc .loc-btn.warn-btn{background:#fff7ee;border-color:#f0dfc9;color:#a65e17}.tsloc .loc-btn.warn-btn:hover{background:#ffefdc}
 .tsloc .loc-btn.reactivate{background:#e8f9ef;border-color:#c4e9d3;color:#087a50}
 .tsloc .loc-msg{border:1px solid;padding:9px 11px;border-radius:8px;margin:0 0 8px;font-size:11px;font-weight:650}
 .tsloc .loc-msg.ok{color:#08764e;background:#eaf9f2;border-color:#c9eedb}.tsloc .loc-msg.err{color:#a52a40;background:#fff0f3;border-color:#f3ccd5}
 .tsloc .loc-msg.note{color:#425d78;background:#f1f6fc;border-color:#d4e2f1}
 .tsloc .loc-btn:disabled,.tsloc .loc-input:disabled{opacity:.55;cursor:not-allowed}
 .tsloc .loc-help{padding:0;margin:0 0 9px;border:1px solid #d8e7f6;border-radius:8px;background:#f2f8ff;color:#3e637f}
 .tsloc .loc-help summary{list-style:none;cursor:pointer;font-size:10px;font-weight:750;padding:7px 11px;display:flex;align-items:center;gap:7px}
 .tsloc .loc-help summary::-webkit-details-marker{display:none}.tsloc .loc-help summary .loc-chevron{margin-left:auto;font-size:9px}
 .tsloc .loc-help[open] summary .loc-chevron{transform:rotate(180deg)}
 .tsloc .loc-help p{margin:0;padding:0 11px 9px;line-height:1.55;font-size:11px}
 .tsloc .loc-card{border:1px solid var(--lc-line);border-radius:11px;overflow:hidden;background:#fff;box-shadow:0 5px 17px rgba(20,46,76,.035);margin-bottom:10px;min-width:0}
 .tsloc .loc-cardhead{padding:10px 13px 9px;border-bottom:1px solid #e7edf4;display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;background:linear-gradient(180deg,#fff,#fbfdff)}
 .tsloc .loc-cardhead h2{font-size:14px;line-height:1.25;margin:0;font-weight:800;color:#153453}
 .tsloc .loc-cardhead p{margin:2px 0 0;color:var(--lc-muted);font-size:10px}
 .tsloc .loc-selector{display:grid;grid-template-columns:minmax(215px,1fr) auto auto;gap:7px;align-items:end}
 .tsloc .loc-field{min-width:0;display:flex;flex-direction:column;gap:4px}
 .tsloc .loc-label{font-size:10px;color:#465e76;font-weight:750}
 .tsloc .loc-input,.tsloc .loc-select{min-width:0;width:100%;height:34px;border:1px solid #cfdaea;border-radius:7px;background:#fff;color:#173959;font:500 11px/1.3 inherit;font-size:11px;padding:0 10px}
 .tsloc .loc-input::placeholder{color:#8999ab}
 .tsloc .loc-selector .loc-field{min-width:245px}
 .tsloc .loc-crumbs{display:flex;align-items:center;gap:6px;flex-wrap:wrap;color:#7186a0;font-size:10px;padding:8px 13px;border-bottom:1px solid #e7edf4;background:#fcfdff}
 .tsloc .loc-crumbs strong{color:#1c4b81}.tsloc .loc-crumbs i{font-size:8px;color:#a0adc0}
 .tsloc .loc-context{padding:13px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:#f7faff;border-bottom:1px solid #e2ebf5}
 .tsloc .loc-context h3{margin:0;font-size:18px;line-height:1.2;color:#16385e}.tsloc .loc-context p{margin:4px 0 0;color:#667d95;font-size:11px}
 .tsloc .loc-selected-metrics{display:flex;gap:8px;flex-wrap:wrap}.tsloc .loc-selected-metrics .loc-metric{min-width:85px;justify-content:center;padding:9px 11px;font-size:11px}.tsloc .loc-selected-metrics strong{font-size:19px}
 .tsloc .loc-manual{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:8px;padding:10px 13px;border-bottom:1px solid #e6edf5;background:#fbfdff}
 .tsloc .loc-manual .loc-addfield{min-width:0}.tsloc .loc-manual .loc-addfield .loc-input{width:100%}
 .tsloc .loc-helper{font-size:10px;color:#72849b;margin:4px 0 0}
 .tsloc .loc-directory-tools{display:flex;align-items:center;justify-content:space-between;gap:8px 12px;flex-wrap:wrap;padding:9px 13px;background:#fff;border-bottom:1px solid #e4ebf4}
 .tsloc .loc-directory-title{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-weight:800;color:#16385e;font-size:12px}
 .tsloc .loc-count{color:#697c91;font-size:10px;font-weight:650;border:1px solid #e0e7ef;background:#f5f8fc;border-radius:6px;padding:3px 6px}
 .tsloc .loc-filter-controls{display:grid;grid-template-columns:minmax(150px,1fr) minmax(100px,130px) minmax(100px,155px) auto auto;gap:6px;align-items:end;flex:1 1 600px;min-width:0}
 .tsloc .loc-filter-controls .loc-input,.tsloc .loc-filter-controls .loc-select{height:32px}
 .tsloc .loc-table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
 .tsloc .loc-table{width:100%;min-width:750px;border-collapse:collapse;table-layout:fixed;text-align:left}
 .tsloc .loc-table thead{background:#eff5fb}.tsloc .loc-table th{padding:9px 12px;text-align:left;font-size:9px;letter-spacing:.32px;text-transform:uppercase;color:#52708c;border-bottom:1px solid #dbe5f0;font-weight:800}
 .tsloc .loc-table td{padding:8px 12px;border-bottom:1px solid #e7edf4;color:#36506a;font-size:11px;vertical-align:middle;height:45px}
 .tsloc .loc-table tbody tr:nth-child(even){background:#fbfdff}.tsloc .loc-table tbody tr:hover{background:#f2f8ff}.tsloc .loc-table tbody tr:last-child td{border-bottom:0}
 .tsloc .loc-name{display:flex;gap:8px;align-items:center;min-width:0}.tsloc .loc-place-icon{display:inline-flex;align-items:center;justify-content:center;flex:none;background:#eef5ff;color:#347acf;width:26px;height:26px;border-radius:7px}
 .tsloc .loc-name strong{display:block;font-weight:750;color:#193a60;overflow-wrap:anywhere}.tsloc .loc-name small{display:block;margin-top:1px;color:#8190a4;font-size:9px}
 .tsloc .loc-tag{display:inline-flex;align-items:center;gap:4px;padding:4px 7px;background:#edf4ff;color:#2e6198;border-radius:6px;font-size:9px;font-weight:800;white-space:nowrap;max-width:100%}
 .tsloc .loc-tag.source-manual{background:#f2f0ff;color:#654ba1}.tsloc .loc-tag.source-supplier{background:#fff2e7;color:#92590f}
 .tsloc .loc-status{display:inline-flex;align-items:center;gap:5px;border-radius:999px;background:#e8f8f0;color:#08774e;padding:4px 8px;font-size:9px;font-weight:800;white-space:nowrap}
 .tsloc .loc-status.inactive{background:#f4f0eb;color:#8a6040}.tsloc .loc-status i{font-size:7px}
 .tsloc .loc-table .loc-btn{min-height:29px;padding:0 10px;font-size:10px}
 .tsloc .loc-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 13px;background:#fbfdff;border-top:1px solid #e7edf4;color:#778aa2;font-size:10px}
 .tsloc .loc-empty{padding:25px 15px;text-align:center;color:#74869c;font-size:11px}.tsloc .loc-empty i{font-size:20px;color:#9bb2cb;display:block;margin-bottom:7px}
 .tsloc .loc-empty strong{color:#234666}.tsloc .loc-empty p{margin:5px 0}
 .tsloc .loc-readonly{font-size:10px;color:#75869a}.tsloc .loc-empty .loc-btn{margin-top:8px}
 @media(max-width:1100px){.tsloc .loc-selector{grid-template-columns:minmax(210px,1fr) auto auto}.tsloc .loc-filter-controls{flex:1 1 100%;grid-template-columns:minmax(150px,1fr) repeat(2,minmax(110px,170px)) auto auto}}
 @media(max-width:680px){.tsloc .loc-header{align-items:flex-start}.tsloc .loc-actions{width:100%}.tsloc .loc-selector{grid-template-columns:1fr 1fr}.tsloc .loc-selector .loc-field{grid-column:1/-1;min-width:0}.tsloc .loc-manual{grid-template-columns:1fr}.tsloc .loc-manual button{justify-self:start}.tsloc .loc-filter-controls{grid-template-columns:1fr 1fr}.tsloc .loc-filter-controls .loc-field:first-of-type{grid-column:1/-1}.tsloc .loc-cardhead{align-items:stretch}.tsloc .loc-cardhead .loc-selector{width:100%}.tsloc .loc-selector .loc-btn{width:100%}.tsloc .loc-table{min-width:710px}.tsloc .loc-context{align-items:flex-start}.tsloc .loc-selected-metrics{width:100%}.tsloc .loc-selected-metrics .loc-metric{flex:1;min-width:0}}
 @media(prefers-reduced-motion:reduce){.tsloc *{transition:none!important}}
 </style>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" media="screen">

<!-- TRAVSCOPE V24.99 responsive screen foundation -->
<link rel="stylesheet" href="assets/travscope-responsive-core-v2499.css?v=2499" media="screen">
<link rel="stylesheet" href="assets/travscope-responsive-admin-v2499.css?v=2499" media="screen">
<link rel="stylesheet" href="assets/travscope-experience-v2500.css?v=2500" media="screen">
<script defer src="assets/travscope-responsive-v2499.js?v=2499"></script>
<script defer src="assets/travscope-experience-v2500.js?v=2500" data-ts25-area="admin"></script>
</head>
<body>
<main class="main"><div class="content"><div class="tsloc">
 <header class="loc-header">
  <div class="loc-heading">
   <div><h1>Cities &amp; Locations</h1><p class="loc-subtitle">One location directory for packages, hotels and supplier services.</p></div>
   <div class="loc-metrics" aria-label="Directory totals">
    <span class="loc-metric"><i class="fa-solid fa-earth-asia" aria-hidden="true"></i> <strong><?=number_format(count($destinations))?></strong> Destinations</span>
    <span class="loc-metric"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <strong><?=number_format($totalLocations)?></strong> Total locations</span>
   </div>
  </div>
  <div class="loc-actions"><a class="loc-btn" href="admin-destinations.php"><i class="fa-solid fa-globe-asia" aria-hidden="true"></i> Destination Master <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true" style="font-size:9px"></i></a></div>
 </header>
 <?php if($schemaError):?><div class="loc-msg err" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <?=dlE($schemaError)?></div><?php endif;?>
 <?php if($success):?><div class="loc-msg ok" role="status" <?=$syncOutcome?'data-sync-outcome="'.dlE($syncOutcome).'"':''?>><i class="fa-solid fa-check-circle" aria-hidden="true"></i> <?=dlE($success)?></div><?php endif;?>
 <?php if($notice):?><div class="loc-msg note" role="status" <?=$syncOutcome?'data-sync-outcome="'.dlE($syncOutcome).'"':''?>><i class="fa-solid fa-circle-info" aria-hidden="true"></i> <?=dlE($notice)?></div><?php endif;?>
 <?php if($error):?><div class="loc-msg err" role="alert" <?=$syncOutcome?'data-sync-outcome="'.dlE($syncOutcome).'"':''?>><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <?=dlE($error)?></div><?php endif;?>
 <details class="loc-help"><summary><i class="fa-solid fa-circle-info" aria-hidden="true"></i> How Location Master works <span style="font-weight:500;color:#6986a1">— supplier hotel, vehicle and activity modules use these destination-linked places</span><i class="fa-solid fa-chevron-down loc-chevron" aria-hidden="true"></i></summary><p>Select a destination, add a location manually or synchronize its city list from the configured geographic service. Supplier-entered new cities can also be saved here automatically. Ensure that a manually added place belongs to the selected destination; this directory currently stores destination-linked locations rather than separate district records.</p></details>
 <section class="loc-card" aria-label="Cities and locations directory">
  <div class="loc-cardhead">
   <div><h2>Location Directory</h2><p>Select a destination to view and manage its child locations.</p></div>
   <form method="get" class="loc-selector" aria-label="Select destination">
    <input type="hidden" name="q" value="<?=dlE($filterQ)?>"><input type="hidden" name="status" value="<?=dlE($filterStatus)?>"><input type="hidden" name="source" value="<?=dlE($filterSource)?>">
    <div class="loc-field"><label class="loc-label" for="locDestination">Destination</label>
     <select class="loc-select" name="destination_id" id="locDestination" onchange="this.form.submit()">
      <option value="0">Select destination</option>
      <?php foreach($destinations as $d):?><option value="<?=(int)$d['id']?>" <?=$selected===(int)$d['id']?'selected':''?>><?=dlE($d['name'])?><?=!empty($d['country'])?' · '.dlE($d['country']):''?><?=($d['status']??'')==='inactive'?' (Inactive)':''?></option><?php endforeach;?>
     </select>
    </div>
    <a class="loc-btn" id="locReload" href="<?=dlE($reloadUrl)?>" title="Reload selected destination"><i class="fa-solid fa-rotate" aria-hidden="true"></i> Reload</a>
    <?php if($destination):?><button class="loc-btn green-btn" id="locSyncButton" type="submit" form="locSyncForm" <?=$canManage?'':'disabled'?> title="Import available city names for the selected destination"><i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i> Sync Cities</button><?php endif;?>
   </form>
  </div>
  <?php if($selected>0 && $destination):?>
   <div class="loc-context" aria-label="Selected destination summary">
    <div><h3><?=dlE($destination['name'])?></h3><p><?=dlE($destination['country']?:'Country not set')?> · Saved cities &amp; locations<?=$canManage?'':' · Inactive destination'?></p></div>
    <div class="loc-selected-metrics" aria-label="Selected destination counts"><span class="loc-metric"><strong id="locSelectedTotal"><?=number_format(count($locations))?></strong> Total</span><span class="loc-metric green"><strong id="locSelectedActive"><?=number_format($selectedActive)?></strong> Active</span><span class="loc-metric orange"><strong id="locSelectedInactive"><?=number_format($selectedInactive)?></strong> Inactive</span></div>
   </div>
   <?php if(!$canManage):?><div class="loc-msg note">This destination is inactive. <a href="admin-destinations.php">Activate it in Destination Master</a> before adding, syncing or changing locations.</div><?php endif;?>
   <form method="post" id="locSyncForm"><input type="hidden" name="csrf" value="<?=dlE($csrf)?>"><input type="hidden" name="action" value="sync_geo"><input type="hidden" name="destination_id" value="<?=$selected?>"><input type="hidden" name="return_q" value="<?=dlE($filterQ)?>"><input type="hidden" name="return_status" value="<?=dlE($filterStatus)?>"><input type="hidden" name="return_source" value="<?=dlE($filterSource)?>"></form>
   <form method="post" id="locAddForm" class="loc-manual" aria-label="Add a child location">
    <input type="hidden" name="csrf" value="<?=dlE($csrf)?>"><input type="hidden" name="action" value="add_location"><input type="hidden" name="destination_id" value="<?=$selected?>">
    <input type="hidden" name="return_q" value="<?=dlE($filterQ)?>"><input type="hidden" name="return_status" value="<?=dlE($filterStatus)?>"><input type="hidden" name="return_source" value="<?=dlE($filterSource)?>">
    <div class="loc-addfield loc-field"><label class="loc-label" for="locName">Add City / Location</label><input class="loc-input" id="locName" name="location_name" required maxlength="150" autocomplete="off" <?=$canManage?'':'disabled'?> placeholder="Enter a city or place within <?=dlE($destination['name'])?>"></div>
    <button class="loc-btn blue" id="locAddButton" type="submit" <?=$canManage?'':'disabled'?>><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Location</button>
   </form>
   <div class="loc-directory-tools">
    <div class="loc-directory-title"><i class="fa-solid fa-list-ul" aria-hidden="true" style="color:#458bd5"></i> <?=dlE($destination['name'])?> locations <span id="locVisibleCount" class="loc-count" aria-live="polite"><?=number_format($visibleLocations)?> of <?=number_format(count($locations))?> records</span></div>
    <form method="get" id="locFilterForm" class="loc-filter-controls" role="search" aria-label="Filter saved locations">
     <input type="hidden" name="destination_id" value="<?=$selected?>">
     <div class="loc-field"><label class="loc-label" for="locSearch">Search</label><input id="locSearch" name="q" value="<?=dlE($filterQ)?>" class="loc-input" type="search" placeholder="Search location..." aria-label="Search location by name"></div>
     <div class="loc-field"><label class="loc-label" for="locStatusFilter">Status</label><select id="locStatusFilter" name="status" class="loc-select" aria-label="Filter location status"><?php foreach(['all'=>'All Status','active'=>'Active','inactive'=>'Inactive'] as $value=>$label):?><option value="<?=$value?>" <?=$filterStatus===$value?'selected':''?>><?=$label?></option><?php endforeach;?></select></div>
     <div class="loc-field"><label class="loc-label" for="locSourceFilter">Source</label><select id="locSourceFilter" name="source" class="loc-select" aria-label="Filter location source"><option value="all" <?=$filterSource==='all'?'selected':''?>>All Sources</option><?php $sourceOptions=[];foreach($locations as $l){$source=strtolower((string)($l['source']??''));if($source!=='')$sourceOptions[$source]=1;}if($filterSource!=='all')$sourceOptions[$filterSource]=1;ksort($sourceOptions);foreach(array_keys($sourceOptions) as $src):?><option value="<?=dlE($src)?>" <?=$filterSource===$src?'selected':''?>><?=dlE($locationSourceLabels[$src]??ucwords(str_replace('_',' ',$src)))?></option><?php endforeach;?></select></div>
     <button class="loc-btn" type="submit">Apply</button><a id="locClearFilters" class="loc-btn" href="admin-destination-locations.php?destination_id=<?=$selected?>">Clear</a>
    </form>
   </div>
   <?php if(!$locations):?>
    <div class="loc-empty"><i class="fa-solid fa-map-location-dot" aria-hidden="true"></i><strong>No saved locations for <?=dlE($destination['name'])?></strong><p><?=$canManage?'Add the first city or place manually above. Sync Cities can import a list when the geographic service has one.':'Activate this destination in Destination Master to add its first location.'?></p><?php if($canManage):?><a class="loc-btn blue" id="locEmptyAdd" href="#locName">Add first location</a><?php endif;?></div>
   <?php else:?>
    <div class="loc-table-wrap"><table class="loc-table" aria-label="Child location directory"><thead><tr><th scope="col" style="width:33%">City / Location</th><th scope="col" style="width:13%">Type</th><th scope="col" style="width:20%">Data Source</th><th scope="col" style="width:14%">Status</th><th scope="col" style="width:20%">Action</th></tr></thead><tbody id="locRows">
     <?php foreach($locations as $l):$isActive=strtolower((string)($l['status']??''))==='active';$sourceName=strtolower((string)($l['source']??''));?>
     <tr data-location-name="<?=dlE((string)$l['location_name'])?>" data-location-status="<?=$isActive?'active':'inactive'?>" data-location-source="<?=dlE($sourceName)?>" <?=$locationMatches($l)?'':'hidden style="display:none"'?>>
      <td><div class="loc-name"><span class="loc-place-icon"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span><div><strong><?=dlE($l['location_name'])?></strong><small><?=dlE($destination['name'])?></small></div></div></td>
      <td><?=dlE(ucwords(str_replace('_',' ',(string)($l['location_type']??'city'))))?></td>
      <td><span class="loc-tag <?=$sourceName==='manual'?'source-manual':(strpos($sourceName,'supplier')===0?'source-supplier':'')?>" title="<?=dlE($sourceName)?>"><?=dlE($locationSourceLabels[$sourceName]??ucwords(str_replace('_',' ',$sourceName)))?></span></td>
      <td><span class="loc-status <?=$isActive?'':'inactive'?>"><i class="fa-solid fa-circle" aria-hidden="true"></i> <?=$isActive?'Active':'Inactive'?></span></td>
      <td><?php if($canManage):?><form method="post"><input type="hidden" name="csrf" value="<?=dlE($csrf)?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="desired_status" value="<?=$isActive?'inactive':'active'?>"><input type="hidden" name="destination_id" value="<?=$selected?>"><input type="hidden" name="location_id" value="<?=(int)$l['id']?>"><input type="hidden" name="return_q" value="<?=dlE($filterQ)?>"><input type="hidden" name="return_status" value="<?=dlE($filterStatus)?>"><input type="hidden" name="return_source" value="<?=dlE($filterSource)?>"><button class="loc-btn <?=$isActive?'warn-btn':'reactivate'?>" type="submit" aria-label="<?=$isActive?'Deactivate':'Activate'?> <?=dlE($l['location_name'])?>"><i class="fa-solid <?=$isActive?'fa-circle-pause':'fa-circle-check'?>" aria-hidden="true"></i> <?=$isActive?'Deactivate':'Activate'?></button></form><?php else:?><span class="loc-readonly">Activate destination first</span><?php endif;?></td>
     </tr><?php endforeach;?>
    </tbody></table></div>
    <div id="locNoMatches" class="loc-empty" style="display:<?=$visibleLocations?'none':'block'?>"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><strong>No matching locations</strong><p>Try a different search, source or status filter, or clear the filters.</p><a class="loc-btn" href="admin-destination-locations.php?destination_id=<?=$selected?>">Clear filters</a></div>
    <div class="loc-foot"><span>Showing saved locations for <?=dlE($destination['name'])?></span><span>Changes to status are saved individually.</span></div>
   <?php endif;?>
  <?php else:?>
   <div class="loc-empty"><i class="fa-solid fa-globe" aria-hidden="true"></i><strong><?=$selected>0?'Selected destination is unavailable':'Choose a destination to start'?></strong><p>Select a destination above to view its saved cities and locations.</p></div>
  <?php endif;?>
 </section>
</div></div></main>
<script src="assets/travscope-pro-admin.js?v=20260916-v9" defer></script><script src="site-brand-sync.js?v=20261005-v2473" defer></script>
<script id="travscope-v2452-location-filter">
(function(){'use strict';
 var search=document.getElementById('locSearch'),status=document.getElementById('locStatusFilter'),source=document.getElementById('locSourceFilter'),tbody=document.getElementById('locRows'),count=document.getElementById('locVisibleCount'),none=document.getElementById('locNoMatches');
 var emptyAdd=document.getElementById('locEmptyAdd'),name=document.getElementById('locName');if(emptyAdd&&name)emptyAdd.addEventListener('click',function(){name.focus();});
 if(!search||!status||!source)return;
 var rows=tbody?Array.prototype.slice.call(tbody.querySelectorAll('tr[data-location-name]')):[];
 function preserveFilters(){var values={q:search.value,status:status.value,source:source.value};
  Object.keys(values).forEach(function(key){document.querySelectorAll('input[type="hidden"][name="return_'+key+'"],.loc-selector input[type="hidden"][name="'+key+'"]').forEach(function(input){input.value=values[key];});});
  var url=new URL(window.location.href);Object.keys(values).forEach(function(key){url.searchParams.set(key,values[key]);});
  window.history.replaceState(null,'',url.pathname+url.search);var reload=document.getElementById('locReload');if(reload)reload.href=url.pathname+url.search;
 }
 function applyFilters(persist){var q=search.value.trim().toLocaleLowerCase(),st=status.value,so=source.value,n=0;
  rows.forEach(function(row){var match=(!q||(row.getAttribute('data-location-name')||'').toLocaleLowerCase().indexOf(q)!==-1)&&(st==='all'||row.getAttribute('data-location-status')===st)&&(so==='all'||row.getAttribute('data-location-source')===so);row.hidden=!match;row.style.display=match?'':'none';if(match)n++;});
  if(count)count.textContent=n.toLocaleString()+' of '+rows.length.toLocaleString()+' records';if(none)none.style.display=n?'none':'block';
  if(persist)preserveFilters();
 }
 search.addEventListener('input',function(){applyFilters(true);});status.addEventListener('change',function(){applyFilters(true);});source.addEventListener('change',function(){applyFilters(true);});applyFilters(false);
})();
</script>
</body></html>
