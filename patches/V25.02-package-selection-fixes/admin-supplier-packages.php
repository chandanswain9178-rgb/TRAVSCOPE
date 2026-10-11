<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/supplier-manual-package-functions.php';
require_once __DIR__.'/package-supplier-functions.php';
if(is_file(__DIR__.'/supplier-hotel-option-functions.php'))require_once __DIR__.'/supplier-hotel-option-functions.php';
if(is_file(__DIR__.'/supplier-vehicle-option-functions.php'))require_once __DIR__.'/supplier-vehicle-option-functions.php';
if(function_exists('requireAdmin'))requireAdmin();elseif(empty($_SESSION['admin_id'])){header('Location: '.(defined('BASE_URL')?BASE_URL:'').'login.php');exit;}
$adminCheck=$pdo->prepare('SELECT id,status FROM admins WHERE id=? LIMIT 1');
$adminCheck->execute([(int)($_SESSION['admin_id']??0)]);$currentAdmin=$adminCheck->fetch(PDO::FETCH_ASSOC);
if(!$currentAdmin||($currentAdmin['status']??'')!=='active'){
    unset($_SESSION['admin_id']);header('Location: '.(defined('BASE_URL')?BASE_URL:'').'admin-login.php');exit;
}
function sppE($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
if(empty($_SESSION['supplier_packages_csrf']))$_SESSION['supplier_packages_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['supplier_packages_csrf'];
$flashSuccess=(string)($_SESSION['supplier_packages_success']??'');unset($_SESSION['supplier_packages_success']);
$flashError=(string)($_SESSION['supplier_packages_error']??'');unset($_SESSION['supplier_packages_error']);
function sppDeleteBlockers(PDO $pdo,int $contractId):array{
    $out=['package_links'=>0,'cost_history'=>0,'operational_history'=>0];
    if($contractId<=0)return $out;
    $versionIds=[];$itineraryIds=[];$groupIds=[];
    try{if(tsMSTable($pdo,'supplier_contract_versions')){$st=$pdo->prepare('SELECT id FROM supplier_contract_versions WHERE contract_id=?');$st->execute([$contractId]);$versionIds=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)?:[]);}}catch(Throwable $e){}
    try{if(tsMSTable($pdo,'supplier_contract_itineraries')){$st=$pdo->prepare('SELECT id FROM supplier_contract_itineraries WHERE contract_id=?');$st->execute([$contractId]);$itineraryIds=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)?:[]);}}catch(Throwable $e){}
    try{if(tsMSTable($pdo,'supplier_contract_rate_groups')){$st=$pdo->prepare('SELECT id FROM supplier_contract_rate_groups WHERE contract_id=?');$st->execute([$contractId]);$groupIds=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)?:[]);}}catch(Throwable $e){}
    try{
        if(tsMSTable($pdo,'package_supplier_options')){
            if(tsMSColumn($pdo,'package_supplier_options','contract_id')){$st=$pdo->prepare('SELECT COUNT(*) FROM package_supplier_options WHERE contract_id=?');$st->execute([$contractId]);$out['package_links']+=(int)$st->fetchColumn();}
            elseif($itineraryIds&&tsMSColumn($pdo,'package_supplier_options','itinerary_id')){$ph=implode(',',array_fill(0,count($itineraryIds),'?'));$st=$pdo->prepare("SELECT COUNT(*) FROM package_supplier_options WHERE itinerary_id IN ($ph)");$st->execute($itineraryIds);$out['package_links']+=(int)$st->fetchColumn();}
        }
    }catch(Throwable $e){}
    try{
        if(tsMSTable($pdo,'cost_sheet_contract_package_sources')&&$versionIds){$ph=implode(',',array_fill(0,count($versionIds),'?'));$st=$pdo->prepare("SELECT COUNT(*) FROM cost_sheet_contract_package_sources WHERE contract_version_id IN ($ph)");$st->execute($versionIds);$out['cost_history']+=(int)$st->fetchColumn();}
    }catch(Throwable $e){}
    try{
        if(tsMSTable($pdo,'cost_sheet_rate_sources')&&tsMSColumn($pdo,'cost_sheet_rate_sources','supplier_contract_id')){$st=$pdo->prepare('SELECT COUNT(*) FROM cost_sheet_rate_sources WHERE supplier_contract_id=?');$st->execute([$contractId]);$out['cost_history']+=(int)$st->fetchColumn();}
    }catch(Throwable $e){}
    foreach(['trip_hotel_allocations','trip_transport_allocations'] as $t){
        try{if($versionIds&&tsMSTable($pdo,$t)&&tsMSColumn($pdo,$t,'source_contract_version_id')){$ph=implode(',',array_fill(0,count($versionIds),'?'));$st=$pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE source_contract_version_id IN ($ph)");$st->execute($versionIds);$out['operational_history']+=(int)$st->fetchColumn();}}catch(Throwable $e){}
    }
    return $out;
}
function sppDeleteSupplierPackage(PDO $pdo,int $contractId):void{
    $st=$pdo->prepare("SELECT id,contract_name FROM supplier_contracts WHERE id=? AND record_type='package' LIMIT 1");$st->execute([$contractId]);$pkg=$st->fetch(PDO::FETCH_ASSOC);if(!$pkg)throw new RuntimeException('Supplier package not found or already deleted.');
    $block=sppDeleteBlockers($pdo,$contractId);
    if($block['cost_history']>0||$block['operational_history']>0){
        throw new RuntimeException('Cannot permanently delete “'.(string)$pkg['contract_name'].'” because it is already used in quotation/costing/operations history. Archive it instead.');
    }
    $pdo->beginTransaction();
    try{
        $versionIds=[];$itineraryIds=[];$groupIds=[];$rateCardIds=[];
        if(tsMSTable($pdo,'supplier_contract_versions')){$q=$pdo->prepare('SELECT id FROM supplier_contract_versions WHERE contract_id=?');$q->execute([$contractId]);$versionIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);}
        if(tsMSTable($pdo,'supplier_contract_itineraries')){$q=$pdo->prepare('SELECT id FROM supplier_contract_itineraries WHERE contract_id=?');$q->execute([$contractId]);$itineraryIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);}
        if(tsMSTable($pdo,'supplier_contract_rate_groups')){$q=$pdo->prepare('SELECT id FROM supplier_contract_rate_groups WHERE contract_id=?');$q->execute([$contractId]);$groupIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);}
        if(tsMSTable($pdo,'supplier_rate_cards')&&tsMSColumn($pdo,'supplier_rate_cards','contract_id')){$q=$pdo->prepare('SELECT id FROM supplier_rate_cards WHERE contract_id=?');$q->execute([$contractId]);$rateCardIds=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);}
        $delIds=function(string $table,string $column,array $ids)use($pdo){if(!$ids||!tsMSTable($pdo,$table)||!tsMSColumn($pdo,$table,$column))return;$ph=implode(',',array_fill(0,count($ids),'?'));$pdo->prepare("DELETE FROM `$table` WHERE `$column` IN ($ph)")->execute($ids);};
        $delEq=function(string $table,string $column,int $id)use($pdo){if(!tsMSTable($pdo,$table)||!tsMSColumn($pdo,$table,$column))return;$pdo->prepare("DELETE FROM `$table` WHERE `$column`=?")->execute([$id]);};

        // Unlink Package Master references without deleting the Package Master package.
        if(tsMSTable($pdo,'package_supplier_options')){
            if(tsMSColumn($pdo,'package_supplier_options','contract_id'))$delEq('package_supplier_options','contract_id',$contractId);
            if($itineraryIds&&tsMSColumn($pdo,'package_supplier_options','itinerary_id'))$delIds('package_supplier_options','itinerary_id',$itineraryIds);
            if($versionIds&&tsMSColumn($pdo,'package_supplier_options','contract_version_id'))$delIds('package_supplier_options','contract_version_id',$versionIds);
        }
        if(tsMSTable($pdo,'packages')){
            if($versionIds&&tsMSColumn($pdo,'packages','source_contract_version_id')){$ph=implode(',',array_fill(0,count($versionIds),'?'));$pdo->prepare("UPDATE packages SET source_contract_version_id=NULL WHERE source_contract_version_id IN ($ph)")->execute($versionIds);}
            if($itineraryIds&&tsMSColumn($pdo,'packages','source_itinerary_id')){$ph=implode(',',array_fill(0,count($itineraryIds),'?'));$pdo->prepare("UPDATE packages SET source_itinerary_id=NULL WHERE source_itinerary_id IN ($ph)")->execute($itineraryIds);}
        }

        // Delete deepest package children first.
        $delIds('supplier_contract_pax_rates','rate_group_id',$groupIds);
        $delIds('supplier_contract_pax_rates','itinerary_id',$itineraryIds);
        $delIds('supplier_contract_pax_rates','contract_version_id',$versionIds);
        $delIds('supplier_contract_day_itineraries','itinerary_id',$itineraryIds);
        $delIds('supplier_contract_activities','contract_version_id',$versionIds);
        $delIds('supplier_contract_policy_items','contract_version_id',$versionIds);
        $delIds('supplier_contract_version_documents','contract_version_id',$versionIds);
        $delIds('supplier_rate_items','rate_card_id',$rateCardIds);
        $delEq('supplier_contract_hotel_inventory','contract_id',$contractId);
        $delEq('supplier_contract_vehicle_rules','contract_id',$contractId);
        $delEq('supplier_contract_rate_groups','contract_id',$contractId);
        $delEq('supplier_contract_itineraries','contract_id',$contractId);
        $delEq('supplier_rate_cards','contract_id',$contractId);
        $delEq('supplier_contract_versions','contract_id',$contractId);

        /*
         * V24.9 test-data hard delete fallback.
         * At this point costing/operations history has already been checked and
         * blocked above. Some older/imported schemas still contain auxiliary FK
         * rows that are not present on every installation. We have explicitly
         * removed all known package children first; temporarily disabling FK
         * checks only for the final supplier-contract delete prevents a stale
         * optional FK from making the UI Delete button appear broken.
         */
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); } catch (Throwable $ignored) {}
        try {
            $d=$pdo->prepare("DELETE FROM supplier_contracts WHERE id=? AND record_type='package'");
            $d->execute([$contractId]);
        } finally {
            try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ignored) {}
        }
        if($d->rowCount()<1)throw new RuntimeException('Supplier package could not be deleted. Refresh and try again.');
        $pdo->commit();
    }catch(Throwable $e){
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ignored) {}
        if($pdo->inTransaction())$pdo->rollBack();
        if(str_contains(strtolower($e->getMessage()),'foreign key constraint'))throw new RuntimeException('Delete is blocked by another linked record: '.$e->getMessage());
        throw $e;
    }
}
/**
 * Resolve saved supplier content for an editable Package Master draft.
 * Prefer an active season/itinerary, then eligible review content. Pricing
 * readiness is checked separately by the supplier quotation workflow.
 */
function sppPackageMasterBulkCandidate(PDO $pdo,int $contractId):array{
    if($contractId<=0)return [];
    $sql="SELECT i.id itinerary_id,i.contract_id,i.supplier_id,i.supplier_package_code,i.title,
                 v.id contract_version_id,v.rate_priority,v.valid_from,v.valid_to,v.version_no,
                 c.contract_name,c.status contract_status,i.status itinerary_status,v.status version_status
          FROM supplier_contract_itineraries i
          JOIN supplier_contracts c ON c.id=i.contract_id
          JOIN suppliers s ON s.id=c.supplier_id AND s.id=i.supplier_id
          JOIN supplier_contract_versions v ON v.id=i.contract_version_id AND v.contract_id=c.id
          WHERE i.contract_id=? AND c.record_type='package'
            AND s.status='active' AND c.status IN ('active','draft')
            AND i.status IN ('active','review') AND v.status IN ('active','review')
          ORDER BY (v.status='active' AND i.status='active') DESC,
                   v.rate_priority DESC,v.valid_from DESC,v.version_no DESC,i.id DESC
          LIMIT 1";
    $st=$pdo->prepare($sql);$st->execute([$contractId]);
    return $st->fetch(PDO::FETCH_ASSOC)?:[];
}

/** Return the existing Package Master id when this supplier contract is already linked. */
function sppExistingPackageMasterForContract(PDO $pdo,int $contractId):int{
    if($contractId<=0)return 0;
    if(tsPSOTableExists($pdo,'package_supplier_options')&&tsPSOColumnExists($pdo,'package_supplier_options','contract_id')){
        $st=$pdo->prepare("SELECT o.package_id FROM package_supplier_options o JOIN packages p ON p.id=o.package_id WHERE o.contract_id=? ORDER BY o.is_primary DESC,o.id LIMIT 1");
        $st->execute([$contractId]);$id=(int)($st->fetchColumn()?:0);if($id>0)return $id;
    }
    if(tsPSOTableExists($pdo,'packages')&&tsPSOColumnExists($pdo,'packages','source_itinerary_id')){
        $st=$pdo->prepare("SELECT p.id FROM packages p JOIN supplier_contract_itineraries i ON i.id=p.source_itinerary_id WHERE i.contract_id=? ORDER BY p.id LIMIT 1");
        $st->execute([$contractId]);$id=(int)($st->fetchColumn()?:0);if($id>0)return $id;
    }
    return 0;
}

function sppBulkAddToPackageMaster(PDO $pdo,array $contractIds,int $adminId,int $supplierId=0):array{
    if(!tsPSOReady($pdo))throw new RuntimeException('Package Master supplier-linking tables are not ready.');
    foreach($contractIds as $id){
        if((!is_int($id)&&(!is_string($id)||!ctype_digit($id)))||(int)$id<=0)throw new RuntimeException('The supplier package selection is invalid. Refresh the page and try again.');
    }
    $contractIds=array_values(array_unique(array_map('intval',$contractIds)));
    if(!$contractIds)throw new RuntimeException('Select at least one supplier package to add to Package Master.');
    if($pdo->inTransaction())throw new RuntimeException('Bulk Package Master creation requires its own transaction.');
    $result=['created'=>0,'already_linked'=>0,'not_ready'=>0,'failed'=>0,'package_ids'=>[],'messages'=>[]];
    foreach($contractIds as $contractId){
        try{
            $pdo->beginTransaction();
            // Lock before reading existing links so simultaneous submissions
            // see the first completed import and safely skip it.
            $lock=$pdo->prepare("SELECT id,supplier_id FROM supplier_contracts WHERE id=? AND record_type='package' FOR UPDATE");
            $lock->execute([$contractId]);$contract=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$contract)throw new RuntimeException('Supplier package was not found.');
            if($supplierId>0&&(int)$contract['supplier_id']!==$supplierId)throw new RuntimeException('Supplier package does not belong to the selected supplier.');
            $existing=sppExistingPackageMasterForContract($pdo,$contractId);
            if($existing>0){$pdo->commit();$result['already_linked']++;continue;}
            $candidate=sppPackageMasterBulkCandidate($pdo,$contractId);
            if(!$candidate){$pdo->commit();$result['not_ready']++;$result['messages'][]='Contract #'.$contractId.' skipped: no eligible active or review supplier content.';continue;}
            $packageId=tsPSOCreatePackageFromSupplier($pdo,(int)$candidate['itinerary_id'],$adminId,true);
            $pdo->commit();
            $result['created']++;$result['package_ids'][]=$packageId;
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            $result['failed']++;
            $result['messages'][]='Contract #'.$contractId.': '.$e->getMessage();
        }
    }
    return $result;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $returnSupplier=max(0,(int)($_POST['supplier_id']??0));$returnQ=trim((string)($_POST['q']??''));
    try{
        if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Security verification failed. Refresh the page and try again.');
        $ids=[];
        if(isset($_POST['add_selected_master'])){
            $ids=(array)($_POST['selected']??[]);
            $bulk=sppBulkAddToPackageMaster($pdo,$ids,(int)($_SESSION['admin_id']??0),$returnSupplier);
            $parts=[];
            if($bulk['created']>0)$parts[]=$bulk['created'].' Draft Package Master package'.($bulk['created']===1?'':'s').' created';
            if($bulk['already_linked']>0)$parts[]=$bulk['already_linked'].' already linked (skipped)';
            if($bulk['not_ready']>0)$parts[]=$bulk['not_ready'].' not ready (skipped)';
            if($bulk['failed']>0)$parts[]=$bulk['failed'].' failed';
            if($bulk['created']>0||$bulk['already_linked']>0)$_SESSION['supplier_packages_success']=implode(' · ',$parts).'. Existing Package Master records were not duplicated or overwritten.';
            if($bulk['messages'])$_SESSION['supplier_packages_error']=implode(' | ',array_slice($bulk['messages'],0,8)).(count($bulk['messages'])>8?' | +'.(count($bulk['messages'])-8).' more warning(s).':'');
        }else{
            if(isset($_POST['delete_single']))$ids=[max(0,(int)$_POST['delete_single'])];
            elseif(isset($_POST['delete_selected']))$ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['selected']??[])),fn($v)=>$v>0)));
            else throw new RuntimeException('No bulk action was selected.');
            if(!$ids)throw new RuntimeException('Select at least one supplier package to delete.');
            $deleted=0;$blocked=[];
            foreach($ids as $id){try{sppDeleteSupplierPackage($pdo,$id);$deleted++;}catch(Throwable $e){$blocked[]=$e->getMessage();}}
            if($deleted>0)$_SESSION['supplier_packages_success']=$deleted.' supplier package'.($deleted===1?'':'s').' permanently deleted.';
            if($blocked)$_SESSION['supplier_packages_error']=implode(' | ',$blocked);
        }
    }catch(Throwable $e){$_SESSION['supplier_packages_error']=$e->getMessage();}
    $qs=[];if($returnSupplier>0)$qs['supplier_id']=$returnSupplier;if($returnQ!=='')$qs['q']=$returnQ;
    header('Location: admin-supplier-packages.php'.($qs?'?'.http_build_query($qs):'').'#supplier-package-directory');exit;
}
$ready=tsMSReady($pdo);$supplierId=max(0,(int)($_GET['supplier_id']??0));$q=trim((string)($_GET['q']??''));
$suppliers=[];try{$suppliers=$pdo->query("SELECT id,company_name,supplier_code,priority,status FROM suppliers WHERE status='active' ORDER BY CASE priority WHEN 'preferred' THEN 1 WHEN 'regular' THEN 2 ELSE 3 END,company_name")->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){}if($supplierId<=0&&count($suppliers)===1)$supplierId=(int)$suppliers[0]['id'];$supplier=$supplierId>0?tsMSSupplier($pdo,$supplierId):null;
$rows=[];$stats=['packages'=>0,'active'=>0,'seasons'=>0,'rates'=>0,'ready'=>0];
if($ready){$sql="SELECT c.*,s.company_name,s.supplier_code,d.name destination_name,
(SELECT COUNT(*) FROM supplier_contract_versions v WHERE v.contract_id=c.id) season_count,
(SELECT COUNT(DISTINCT g.id) FROM supplier_contract_rate_groups g WHERE g.contract_id=c.id AND g.status='active') category_count,
(SELECT COUNT(*) FROM supplier_contract_pax_rates p JOIN supplier_contract_itineraries i ON i.id=p.itinerary_id WHERE i.contract_id=c.id AND p.status='active') active_pax_rows,
(SELECT COUNT(*) FROM package_supplier_options o WHERE o.contract_id=c.id AND o.status='active') travscope_links,
(SELECT i.supplier_package_code FROM supplier_contract_itineraries i WHERE i.contract_id=c.id ORDER BY i.id LIMIT 1) supplier_package_code,
(SELECT i.title FROM supplier_contract_itineraries i WHERE i.contract_id=c.id ORDER BY i.id LIMIT 1) supplier_package_title,
(SELECT i.duration_nights FROM supplier_contract_itineraries i WHERE i.contract_id=c.id ORDER BY i.id LIMIT 1) duration_nights,
(SELECT i.duration_days FROM supplier_contract_itineraries i WHERE i.contract_id=c.id ORDER BY i.id LIMIT 1) duration_days,
EXISTS(SELECT 1 FROM supplier_contract_versions v WHERE v.contract_id=c.id AND v.status='active' AND CURDATE() BETWEEN v.valid_from AND v.valid_to AND EXISTS(SELECT 1 FROM supplier_contract_pax_rates p WHERE p.contract_version_id=v.id AND p.status='active')) quote_ready
FROM supplier_contracts c JOIN suppliers s ON s.id=c.supplier_id LEFT JOIN destinations d ON d.id=(SELECT i2.destination_id FROM supplier_contract_itineraries i2 WHERE i2.contract_id=c.id ORDER BY i2.id LIMIT 1)
WHERE c.record_type='package'";$params=[];if($supplierId>0){$sql.=' AND c.supplier_id=?';$params[]=$supplierId;}if($q!==''){$like='%'.$q.'%';$sql.=' AND (c.contract_name LIKE ? OR c.contract_code LIKE ? OR s.company_name LIKE ? OR EXISTS(SELECT 1 FROM supplier_contract_itineraries ix WHERE ix.contract_id=c.id AND (ix.supplier_package_code LIKE ? OR ix.title LIKE ?)))';array_push($params,$like,$like,$like,$like,$like);}$sql.=' ORDER BY s.company_name,c.status DESC,c.contract_name';$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$stats['packages']=count($rows);foreach($rows as $r){if(($r['status']??'')==='active')$stats['active']++;$stats['seasons']+=(int)$r['season_count'];$stats['rates']+=(int)$r['active_pax_rows'];if(!empty($r['quote_ready']))$stats['ready']++;}}
$coverage=[];if($supplierId>0&&tsMSTable($pdo,'supplier_destinations')){try{$st=$pdo->prepare("SELECT d.id,d.name,d.country FROM supplier_destinations sd JOIN destinations d ON d.id=sd.destination_id WHERE sd.supplier_id=? ORDER BY d.country,d.name");$st->execute([$supplierId]);$coverage=$st->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){}}
$hotelOptions=($supplierId>0&&function_exists('tsSHOCount'))?tsSHOCount($pdo,$supplierId):0;$vehicleOptions=0;if($supplierId>0&&function_exists('tsSVOList')){try{$vehicleOptions=count(tsSVOList($pdo,$supplierId));}catch(Throwable $e){}}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Supplier Packages &amp; Rates | TRAVSCOPE</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="assets/travscope-pro-admin.css?v=20260924-v23">
<style>
:root{--spp-blue:#1673ef;--spp-ink:#142c4e;--spp-muted:#667c97;--spp-line:#e2eaf4;--spp-surface:#fff;--spp-bg:#f4f8fd}
body.ts-pro-admin .main>.content{padding:18px 22px 26px!important}
body.ts-pro-admin .main>.content>.spp-workspace{width:100%!important;max-width:none!important;margin:0!important;padding:0!important}
.spp-workspace{color:var(--spp-ink);font-family:inherit;font-size:13px;line-height:1.48}
.spp-workspace *, .spp-workspace *::before,.spp-workspace *::after{box-sizing:border-box}
.spp-workspace a{text-decoration:none}
.spp-workspace .spp-top{background:linear-gradient(112deg,#fff 0%,#f9fcff 65%,#edf6ff 100%);border:1px solid #dce8f7;border-radius:16px;padding:20px 22px;display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:13px;box-shadow:0 5px 20px rgba(24,61,105,.035)}
.spp-top-left{display:flex;align-items:center;gap:15px;min-width:0}
.spp-top-icon{width:52px;height:52px;flex:none;border-radius:15px;display:grid;place-items:center;background:linear-gradient(145deg,#1673ef,#0757c3);color:#fff;font-size:21px;box-shadow:0 9px 20px rgba(22,115,239,.22)}
.spp-kicker{margin:0 0 3px;font-size:10px;text-transform:uppercase;letter-spacing:.115em;font-weight:850;color:#1673ef}
.spp-workspace h1{font-size:24px;line-height:1.25;font-weight:850;letter-spacing:-.035em;margin:0;color:#122b4e}
.spp-subtitle{font-size:12px;color:#687f9a;margin:5px 0 0;line-height:1.55;max-width:820px}
.spp-top-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap;justify-content:flex-end}
.spp-btn{border:1px solid #d9e5f4;background:#fff;color:#244566;display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:9px;font-weight:750;font-size:12px;min-height:38px;padding:8px 13px;cursor:pointer;transition:background .14s,border-color .14s,transform .14s;white-space:nowrap;font-family:inherit;line-height:1.2}
.spp-btn:hover{background:#f0f7ff;border-color:#b6d3f5;color:#0757b8}
.spp-btn:focus-visible,.spp-field input:focus-visible,.spp-field select:focus-visible,.spp-tab:focus-visible{outline:3px solid #93c5fd;outline-offset:2px}
.spp-btn.primary{color:#fff;background:linear-gradient(135deg,#1673ef,#075bcc);border-color:#1165da;box-shadow:0 6px 15px rgba(18,108,227,.14)}
.spp-btn.primary:hover{background:#0759c8;color:#fff}
.spp-btn.danger{background:#fff5f5;color:#c02a38;border-color:#ffd3d8}
.spp-btn.danger:hover{background:#ffecef;color:#ad1b2d}
.spp-btn:disabled{opacity:.45;cursor:not-allowed;box-shadow:none}
.spp-toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:13px;align-items:center}
.spp-toolbar .spp-btn i{font-size:13px;color:#2681d9}.spp-toolbar .spp-btn:hover i{color:#0757b8}
.spp-toolbar-note{margin-left:auto;color:#8090a8;font-size:11px;font-weight:600}
.spp-filter-panel{background:#fff;border:1px solid var(--spp-line);border-radius:13px;box-shadow:0 4px 16px rgba(19,53,89,.035);display:grid;grid-template-columns:minmax(225px,1.05fr) minmax(210px,.9fr) auto;gap:13px;align-items:end;padding:16px;margin-bottom:13px}
.spp-field label{display:block;color:#526a88;font-size:11px;text-transform:uppercase;letter-spacing:.055em;font-weight:800;margin-bottom:7px}
.spp-field select,.spp-field input{width:100%;min-height:40px;padding:9px 12px;border:1px solid #d8e4f2;background:#fafdff;color:#1a385a;border-radius:9px;font-family:inherit;font-size:13px}
.spp-filter-actions{display:flex;gap:8px}
.spp-metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:11px;margin-bottom:13px}
.spp-metric{background:#fff;border:1px solid var(--spp-line);border-radius:13px;padding:15px 16px;display:flex;align-items:center;gap:12px;min-width:0;box-shadow:0 3px 12px rgba(20,55,101,.025)}
.spp-metric-icon{width:42px;height:42px;flex:none;display:grid;place-items:center;font-size:16px;border-radius:12px;color:#136fe4;background:#eaf3ff}
.spp-metric:nth-child(2) .spp-metric-icon{color:#06966b;background:#e5f8f0}
.spp-metric:nth-child(3) .spp-metric-icon{color:#b16e00;background:#fff2d9}
.spp-metric:nth-child(4) .spp-metric-icon{color:#7548dd;background:#f2ebff}
.spp-metric:nth-child(5) .spp-metric-icon{color:#087d71;background:#e3f8f5}
.spp-metric small{font-size:10px;color:#687f98;text-transform:uppercase;font-weight:800;line-height:1.3;letter-spacing:.035em;display:block}
.spp-metric strong{font-size:23px;line-height:1.25;color:#152e50;letter-spacing:-.04em;font-weight:850;display:block;margin-top:1px}
.spp-card{background:#fff;border:1px solid var(--spp-line);border-radius:14px;margin-bottom:13px;box-shadow:0 4px 18px rgba(22,53,87,.035);overflow:hidden}
.spp-card-header{padding:17px 18px;display:flex;justify-content:space-between;align-items:center;gap:12px;border-bottom:1px solid #e9eef6;background:#fff}
.spp-card-header h2{font-size:16px;line-height:1.3;margin:0;color:#193756;font-weight:850}
.spp-card-header p{font-size:11.5px;color:#6b8097;margin:4px 0 0;line-height:1.5}
.spp-header-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.spp-count{border:1px solid #cbe3ff;background:#f0f7ff;color:#1466c9;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap}
.spp-card-body{padding:15px 17px}
.spp-coverage{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.spp-chip{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:700;border-radius:999px;color:#2e5c8e;background:#edf5ff;padding:6px 9px;border:1px solid #e0ecfd}
.spp-shared-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}
.spp-resource{background:#f9fcff;border:1px solid #e3ecf6;border-radius:11px;padding:12px;display:flex;align-items:center;gap:10px}
.spp-resource-icon{width:37px;height:37px;flex:none;display:grid;place-items:center;border-radius:10px;background:#e8f3ff;color:#1673e9;font-size:15px}
.spp-resource:nth-child(2) .spp-resource-icon{background:#e8f9f3;color:#0e9870}
.spp-resource-info{flex:1;min-width:0}
.spp-resource-info strong{font-size:12px;color:#1e4065;display:block}
.spp-resource-info small{display:block;color:#7c8ca4;font-size:10.5px;margin-top:2px}
.spp-explainer{margin:0 0 13px;border-radius:12px;border:1px solid #d7e8fa;background:#f7fbff;overflow:hidden}
.spp-explainer summary{cursor:pointer;padding:12px 15px;color:#28609b;font-size:12px;font-weight:750;display:flex;align-items:center;gap:10px;list-style:none}
.spp-explainer summary::-webkit-details-marker{display:none}
.spp-explainer summary::after{content:'+';margin-left:auto;font-size:16px;font-weight:700}
.spp-explainer[open] summary::after{content:'−'}
.spp-explainer .spp-guide{padding:0 15px 14px}
.spp-explainer .spp-steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}
.spp-explainer .spp-steps div{background:#fff;border:1px solid #e1ecf8;border-radius:9px;padding:10px;color:#58718c;font-size:11px}
.spp-explainer .spp-steps strong{font-size:11px;display:block;color:#1b4773;margin-bottom:3px}
.spp-explainer .spp-guide p{font-size:11px;color:#476987;margin:9px 0 0}
.spp-alert{padding:12px 14px;border-radius:10px;margin-bottom:12px;font-size:12px;font-weight:650}
.spp-alert.ok{border:1px solid #a9e6c6;background:#f0fff6;color:#087447}
.spp-alert.err{border:1px solid #fec9d0;background:#fff2f3;color:#b42332}
.spp-alert.warn{border:1px solid #f3d7a4;background:#fff8e9;color:#9a5c11}
.spp-inventory-bar{display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap;padding:12px 17px;background:#fbfdff;border-bottom:1px solid #e9eef6}
.spp-status-filter{display:flex;gap:5px;background:#edf3f9;padding:4px;border-radius:10px}
.spp-tab{font:inherit;font-size:11px;font-weight:750;color:#607c99;padding:7px 10px;cursor:pointer;background:transparent;border:0;border-radius:7px;white-space:nowrap}
.spp-tab.is-active{background:#fff;box-shadow:0 2px 7px rgba(19,61,109,.1);color:#1166ce}
.spp-inventory-help{color:#75869b;font-size:11px}
.spp-bulk{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 16px;background:#fff;border-bottom:1px solid #e8edf5}
.spp-bulk-selection{display:flex;gap:10px;align-items:center;color:#526d88;font-size:11px;min-width:0}
.spp-bulk-selection strong{font-size:11px;color:#213e5c;white-space:nowrap}
.spp-bulk-selection span{font-size:10px;color:#8994a5}
.spp-bulk-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.spp-btn.master{color:#fff;background:linear-gradient(135deg,#0b8a62,#06704f);border-color:#087a58;box-shadow:0 6px 15px rgba(8,122,88,.14)}
.spp-btn.master:hover{background:#066347;color:#fff;border-color:#055c42}
.spp-workspace .row-check{width:16px;height:16px;accent-color:#166edc;cursor:pointer;flex:none}
.spp-table-scroller{overflow:auto;max-height:66vh;background:#fff}
.spp-table{border-collapse:separate;border-spacing:0;width:100%;min-width:980px;table-layout:auto}
.spp-table thead th{position:sticky;top:0;z-index:2;background:#f0f5fb;color:#536e8b;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.035em;font-weight:850;padding:11px 12px;border-bottom:1px solid #d9e6f3;white-space:nowrap}
.spp-table tbody tr{transition:background .15s;border-bottom:1px solid #edf2f8}
.spp-table tbody tr:hover{background:#f3f9ff}
.spp-table tbody tr.is-selected{background:#eaf4ff}
.spp-table tbody td{border-bottom:1px solid #ebf0f6;padding:13px 12px;font-size:12px;color:#385570;vertical-align:middle;line-height:1.45}
.spp-table tbody tr:last-child td{border-bottom:0}
.spp-pkg-code{font-size:12px;font-weight:850;color:#0869d0;display:block;margin-bottom:4px;letter-spacing:.012em}
.spp-pkg-title{font-size:12px;font-weight:760;color:#172f51;display:block;line-height:1.4;max-width:400px}
.spp-row-sub{font-size:10.5px;color:#7e8ea2;display:block;margin-top:4px}
.spp-destination{color:#2f5579;font-weight:650;display:block}
.spp-duration{white-space:nowrap;font-weight:800;color:#274c71}
.spp-rate-cells{display:grid;grid-template-columns:repeat(3,minmax(43px,1fr));gap:6px;min-width:151px}
.spp-rate-cells>span{display:block;text-align:center;border:1px solid #e6edf5;background:#f8fbff;border-radius:7px;padding:6px 5px}
.spp-rate-cells strong{font-size:12px;color:#1b4168;display:block;line-height:1.2}
.spp-rate-cells small{font-size:9px;color:#7a8da4;display:block;margin-top:3px}
.spp-badge{display:inline-flex;align-items:center;gap:5px;white-space:nowrap;font-size:10px;font-weight:850;border-radius:999px;padding:5px 9px;background:#e7f8f0;color:#087f58;border:1px solid #c2eed9}
.spp-badge.warn{background:#fff7e7;border-color:#f7dfa8;color:#a96912}
.spp-links{font-weight:850;color:#244c74;font-size:12px}
.spp-link-note{display:block;font-size:9px;margin-top:3px;font-weight:700;color:#1870be}
.spp-link-note.protected{color:#a96812}
.spp-row-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap;min-width:174px}
.spp-row-actions .spp-btn{min-height:32px;font-size:10.5px;padding:7px 9px}
.spp-empty{padding:48px;text-align:center;color:#71849c}
.spp-empty i{font-size:24px;color:#a2bedf;margin-bottom:8px}
.spp-empty h3{color:#2f4b6b;margin:4px 0;font-size:17px}
.spp-empty p{font-size:12px;margin:4px 0 0}
.spp-remaining{padding:10px 15px;background:#fafcff;color:#7287a1;font-size:11px;border-top:1px solid #edf2f7}
@media(max-width:1220px){.spp-top{align-items:flex-start}.spp-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}.spp-top-actions{max-width:280px}}
@media(max-width:900px){.spp-top{flex-wrap:wrap}.spp-filter-panel{grid-template-columns:1fr 1fr}.spp-filter-actions{grid-column:1/-1}.spp-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.spp-explainer .spp-steps{grid-template-columns:1fr 1fr}}
@media(max-width:640px){body.ts-pro-admin .main>.content{padding:12px!important}.spp-top{padding:15px}.spp-workspace h1{font-size:21px}.spp-top-actions{max-width:none;width:100%;justify-content:flex-start}.spp-filter-panel{grid-template-columns:1fr}.spp-filter-actions{grid-column:auto}.spp-metrics{grid-template-columns:1fr 1fr}.spp-metric{padding:10px;gap:7px}.spp-metric-icon{width:33px;height:33px}.spp-metric strong{font-size:19px}.spp-metric small{font-size:9px}.spp-shared-grid{grid-template-columns:1fr}.spp-card-header{align-items:flex-start;flex-direction:column}.spp-inventory-bar{align-items:flex-start}.spp-explainer .spp-steps{grid-template-columns:1fr}.spp-bulk{flex-wrap:wrap}.spp-bulk-selection span{display:none}}

/* V24.69 — compact international supplier package workspace */
.spp-top{flex-wrap:wrap!important;padding:16px 18px!important;gap:12px 18px!important;margin-bottom:10px!important}
.spp-top-icon{width:46px!important;height:46px!important;border-radius:13px!important;font-size:18px!important}
.spp-workspace h1{font-size:22px!important}
.spp-subtitle{margin-top:3px!important}
.spp-head-metrics{flex:1 0 100%;display:flex;align-items:center;gap:7px;flex-wrap:wrap;border-top:1px solid #e5edf7;padding-top:10px}
.spp-head-metric{display:inline-flex;align-items:center;gap:6px;min-height:31px;padding:5px 9px;border:1px solid #e1eaf5;border-radius:9px;background:#fff;color:#365675;box-shadow:0 2px 7px rgba(22,53,87,.025)}
.spp-head-metric i{width:21px;height:21px;border-radius:7px;display:grid;place-items:center;font-size:10px;background:#edf5ff;color:#1673ef}
.spp-head-metric b{font-size:14px;color:#162f4f;line-height:1;font-weight:850}.spp-head-metric small{font-size:9.5px;color:#6c8097;font-weight:750;white-space:nowrap}
.spp-head-metric.green i{background:#e7f8f0;color:#079267}.spp-head-metric.amber i{background:#fff3dd;color:#b36b00}.spp-head-metric.purple i{background:#f2ebff;color:#7548dd}.spp-head-metric.teal i{background:#e5f8f5;color:#087d71}
.spp-commandbar{background:#fff;border:1px solid var(--spp-line);border-radius:13px;box-shadow:0 3px 14px rgba(19,53,89,.03);overflow:hidden;margin-bottom:10px}
.spp-commandbar .spp-toolbar{margin:0!important;padding:9px 11px;border-bottom:1px solid #e9eef6;background:#fbfdff}
.spp-commandbar .spp-toolbar .spp-btn{min-height:32px;padding:6px 10px;font-size:10.5px;border-color:#e0e9f4}
.spp-commandbar .spp-filter-panel{margin:0!important;border:0!important;border-radius:0!important;box-shadow:none!important;padding:10px 11px!important;grid-template-columns:minmax(250px,1.15fr) minmax(260px,1fr) auto!important;gap:9px!important}
.spp-commandbar .spp-field label{font-size:9.5px;margin-bottom:4px}.spp-commandbar .spp-field select,.spp-commandbar .spp-field input{min-height:35px;padding:7px 10px;font-size:11.5px}
.spp-commandbar .spp-filter-actions .spp-btn{min-height:35px;padding:7px 11px;font-size:10.5px}
.spp-explainer{margin-bottom:10px!important}.spp-explainer summary{padding:9px 12px!important;font-size:10.5px!important}
.spp-alert{margin-bottom:10px!important;padding:9px 12px!important;font-size:11px!important}
.spp-supplier-strip{background:#fff;border:1px solid var(--spp-line);border-radius:13px;margin-bottom:10px;padding:10px 12px;display:grid;grid-template-columns:minmax(240px,.8fr) minmax(280px,1.6fr) auto;align-items:center;gap:10px;box-shadow:0 3px 14px rgba(22,53,87,.03)}
.spp-supplier-main{display:flex;align-items:center;gap:9px;min-width:0}.spp-supplier-avatar{width:35px;height:35px;display:grid;place-items:center;flex:none;border-radius:10px;background:#eaf3ff;color:#1673ef}.spp-supplier-copy{min-width:0}.spp-supplier-copy>span{display:block;text-transform:uppercase;letter-spacing:.07em;font-size:8.5px;font-weight:850;color:#6e84a0}.spp-supplier-copy strong{display:block;color:#193756;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.spp-supplier-copy small{display:block;color:#8190a1;font-size:9.5px;margin-top:1px}
.spp-coverage-inline{margin:0!important;max-height:58px;overflow:auto;align-content:flex-start}.spp-coverage-inline .spp-chip{font-size:9px;padding:4px 7px}
.spp-supplier-actions{display:flex;align-items:center;gap:7px;justify-content:flex-end;flex-wrap:wrap}.spp-resource-link{display:inline-flex;align-items:center;gap:7px;min-width:116px;padding:6px 8px;border-radius:9px;border:1px solid #e2ebf5;background:#f9fcff;color:#234a73}.spp-resource-link>i{width:26px;height:26px;display:grid;place-items:center;border-radius:8px;background:#e8f3ff;color:#1673ef}.spp-resource-link.vehicle>i{background:#e8f9f3;color:#0e9870}.spp-resource-link span{line-height:1.1}.spp-resource-link b{display:block;font-size:9.5px}.spp-resource-link small{display:block;font-size:8.5px;color:#8292a5;margin-top:2px}
.spp-card{margin-bottom:10px!important}.spp-card-header{padding:12px 14px!important}.spp-card-header h2{font-size:14px!important}.spp-card-header p{font-size:10px!important;margin-top:2px!important}.spp-card-header .spp-kicker{font-size:8.5px!important}
.spp-inventory-bar{padding:8px 12px!important}.spp-bulk{padding:8px 12px!important}.spp-table thead th{padding:9px 10px!important;font-size:9px!important}.spp-table tbody td{padding:10px!important;font-size:11px!important}
@media(max-width:1050px){.spp-supplier-strip{grid-template-columns:1fr 1fr}.spp-supplier-actions{grid-column:1/-1;justify-content:flex-start}.spp-commandbar .spp-filter-panel{grid-template-columns:1fr 1fr!important}.spp-commandbar .spp-filter-actions{grid-column:1/-1}}
@media(max-width:680px){.spp-head-metrics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));width:100%}.spp-head-metric{min-width:0}.spp-commandbar .spp-filter-panel{grid-template-columns:1fr!important}.spp-commandbar .spp-filter-actions{grid-column:auto}.spp-supplier-strip{grid-template-columns:1fr}.spp-supplier-actions{grid-column:auto;justify-content:flex-start}.spp-coverage-inline{max-height:86px}}
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
<main class="main"><div class="content"><div class="spp-workspace">

<header class="spp-top">
    <div class="spp-top-left">
        <div class="spp-top-icon"><i class="fa-solid fa-suitcase-rolling" aria-hidden="true"></i></div>
        <div>
            <p class="spp-kicker">Supplier control / Wholesale inventory</p>
            <h1>Supplier Packages &amp; Rates</h1>
            <p class="spp-subtitle">Manage supplier package contracts, live season rates and exact passenger slabs in one clear workspace.</p>
        </div>
    </div>
    <div class="spp-top-actions">
        <a class="spp-btn" href="admin-supplier-package-import.php<?=$supplierId?'?supplier_id='.$supplierId:''?>"><i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i> Import Package + Hotel Excel</a>
        <a class="spp-btn primary" href="<?=($supplierId>0?'admin-supplier-package-manual.php?supplier_id='.$supplierId:'admin-suppliers.php')?>"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Package</a>
    </div>
    <div class="spp-head-metrics" aria-label="Supplier package statistics">
        <span class="spp-head-metric blue"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i><b><?=$stats['packages']?></b><small>Packages</small></span>
        <span class="spp-head-metric green"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><b><?=$stats['active']?></b><small>Active</small></span>
        <span class="spp-head-metric amber"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i><b><?=$stats['seasons']?></b><small>Seasons</small></span>
        <span class="spp-head-metric purple"><i class="fa-solid fa-users" aria-hidden="true"></i><b><?=$stats['rates']?></b><small>Exact PAX rows</small></span>
        <span class="spp-head-metric teal"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i><b><?=$stats['ready']?></b><small>Quotation ready</small></span>
    </div>
</header>

<?php if(!$ready&&$flashSuccess!==''):?><div class="spp-alert ok" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <?=sppE($flashSuccess)?></div><?php endif;?>
<?php if(!$ready&&$flashError!==''):?><div class="spp-alert err" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <?=sppE($flashError)?></div><?php endif;?>

<?php if(!$ready):?>
<div class="spp-alert warn">Supplier Package tables are not ready. Import the Manual Supplier Package SQL used by V20.1/V21 first.</div>
<?php else:?>

<section class="spp-commandbar" aria-label="Supplier package controls">
    <nav class="spp-toolbar" aria-label="Supplier tools">
        <a class="spp-btn" href="admin-suppliers.php"><i class="fa-solid fa-building-user" aria-hidden="true"></i> Supplier Directory</a>
        <a class="spp-btn" href="admin-supplier-hotels.php<?=$supplierId?'?supplier_id='.$supplierId:''?>"><i class="fa-solid fa-hotel" aria-hidden="true"></i> Hotel Options</a>
        <a class="spp-btn" href="admin-supplier-vehicles.php<?=$supplierId?'?supplier_id='.$supplierId:''?>"><i class="fa-solid fa-van-shuttle" aria-hidden="true"></i> Vehicle Options</a>
        <span class="spp-toolbar-note"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Protected rate history</span>
    </nav>
    <form class="spp-filter-panel" method="get" aria-label="Find supplier packages">
        <div class="spp-field"><label for="sppSupplierFilter"><i class="fa-regular fa-building" aria-hidden="true"></i> Supplier</label>
            <select id="sppSupplierFilter" name="supplier_id">
                <option value="0">All active suppliers</option>
                <?php foreach($suppliers as $s):?><option value="<?=(int)$s['id']?>" <?=$supplierId===(int)$s['id']?'selected':''?>><?=sppE($s['company_name'])?> · <?=sppE($s['supplier_code']??'')?></option><?php endforeach;?>
            </select>
        </div>
        <div class="spp-field"><label for="sppPackageSearch"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Search Package</label>
            <input id="sppPackageSearch" name="q" value="<?=sppE($q)?>" placeholder="Search code, route, package or supplier">
        </div>
        <div class="spp-filter-actions">
            <button class="spp-btn primary" type="submit"><i class="fa-solid fa-filter" aria-hidden="true"></i> Filter</button>
            <a class="spp-btn" href="admin-supplier-packages.php" title="Clear all filters"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reset</a>
        </div>
    </form>
</section>

<details class="spp-explainer">
    <summary><i class="fa-solid fa-circle-info" aria-hidden="true"></i> How supplier package pricing works <span style="color:#6f8da9;font-weight:500">— quick reference</span></summary>
    <div class="spp-guide">
        <div class="spp-steps">
            <div><strong>01 · Select supplier</strong>Choose the DMC/operator.</div>
            <div><strong>02 · Configure package</strong>Keep route, code and itinerary together.</div>
            <div><strong>03 · Apply seasonal rates</strong>Exact PAX × category is the supplier package rate.</div>
            <div><strong>04 · Fetch into quotation</strong>Travel date + PAX + category selects a valid rate.</div>
        </div>
        <p><strong>Important:</strong> Hotel and vehicle options are shared supplier resources. Package Rate Matrix owns the supplier package cost; hotel and vehicle options are not priced separately here.</p>
    </div>
</details>

<?php if($supplier):?>
<section class="spp-supplier-strip" aria-label="Selected supplier context">
    <div class="spp-supplier-main">
        <span class="spp-supplier-avatar"><i class="fa-solid fa-building" aria-hidden="true"></i></span>
        <div class="spp-supplier-copy"><span>Selected supplier</span><strong><?=sppE($supplier['company_name'])?></strong><small><?=sppE($supplier['supplier_code']??'')?> · supplier-wide resources &amp; coverage</small></div>
    </div>
    <div class="spp-coverage spp-coverage-inline"><?php if($coverage):foreach($coverage as $c):?><span class="spp-chip"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?=sppE($c['name'])?> · <?=sppE($c['country'])?></span><?php endforeach;else:?><span class="spp-chip">No coverage mapped yet</span><?php endif;?></div>
    <div class="spp-supplier-actions">
        <a class="spp-resource-link hotel" href="admin-supplier-hotels.php?supplier_id=<?=$supplierId?>"><i class="fa-solid fa-hotel" aria-hidden="true"></i><span><b>Hotel Options</b><small><?=$hotelOptions?> configured</small></span></a>
        <a class="spp-resource-link vehicle" href="admin-supplier-vehicles.php?supplier_id=<?=$supplierId?>"><i class="fa-solid fa-car-side" aria-hidden="true"></i><span><b>Vehicle Options</b><small><?=$vehicleOptions?> configured</small></span></a>
        <a class="spp-btn" href="admin-supplier-details.php?id=<?=$supplierId?>"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> Supplier 360</a>
    </div>
</section>
<?php endif;?>

<section class="spp-card" id="supplier-package-directory" aria-label="Supplier package directory">
    <div class="spp-card-header">
        <div><p class="spp-kicker">Inventory overview</p><h2>Supplier Package Directory</h2><p>Review coverage, seasonal pricing and Package Master links. Every row is a complete supplier package.</p></div>
        <div class="spp-header-tools"><span class="spp-count"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> <?=count($rows)?> packages</span></div>
    </div>
    <?php if($flashSuccess!==''):?><div class="spp-alert ok" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <?=sppE($flashSuccess)?></div><?php endif;?>
    <?php if($flashError!==''):?><div class="spp-alert err" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <?=sppE($flashError)?></div><?php endif;?>
    <?php if(!$rows):?>
        <div class="spp-empty"><i class="fa-solid fa-box-open" aria-hidden="true"></i><h3>No supplier packages found</h3><p>Try clearing the search, adding a package or importing your Excel/CSV rate matrix.</p></div>
    <?php else:?>
    <form method="post" id="supplierPackageDeleteForm">
        <input type="hidden" name="csrf" value="<?=sppE($csrf)?>">
        <input type="hidden" name="supplier_id" value="<?=$supplierId?>">
        <input type="hidden" name="q" value="<?=sppE($q)?>">
        <div class="spp-inventory-bar">
            <div class="spp-status-filter" role="group" aria-label="Quotation readiness filter">
                <button class="spp-tab is-active" data-filter="all" aria-pressed="true" type="button">All (<?=count($rows)?>)</button>
                <button class="spp-tab" data-filter="ready" aria-pressed="false" type="button">Quotation Ready (<?=$stats['ready']?>)</button>
                <button class="spp-tab" data-filter="review" aria-pressed="false" type="button">Check Rates (<?=$stats['packages']-$stats['ready']?>)</button>
            </div>
            <span class="spp-inventory-help">Readiness is based on active seasonal rates valid today.</span>
        </div>
        <div class="spp-bulk">
            <div class="spp-bulk-selection">
                <input type="checkbox" class="row-check" id="selectAllPackages" aria-label="Select all visible supplier packages">
                <strong id="selectedPackageCount">0 selected</strong>
                <span>Create editable drafts from selected supplier packages. Rates can be completed later; existing links are skipped.</span>
            </div>
            <div class="spp-bulk-actions">
                <button type="submit" name="add_selected_master" value="1" class="spp-btn master" id="bulkPackageMasterBtn" disabled><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Add Selected to Package Master</button>
                <button type="submit" name="delete_selected" value="1" class="spp-btn danger" id="massDeleteBtn" disabled><i class="fa-solid fa-trash-can" aria-hidden="true"></i> Delete Selected</button>
            </div>
        </div>
        <div class="spp-table-scroller">
            <table class="spp-table">
                <thead><tr>
                    <th style="width:40px"><input type="checkbox" class="row-check" id="selectAllPackagesHead" aria-label="Select all visible supplier packages"></th>
                    <th style="min-width:260px">Supplier Package</th>
                    <th>Destination</th>
                    <th>Duration</th>
                    <th>Rate Inventory</th>
                    <th>Quotation</th>
                    <th>Linked</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody id="sppPackageRows">
                <?php foreach($rows as $r):$block=sppDeleteBlockers($pdo,(int)$r['id']);$protected=($block['cost_history']>0);?>
                <tr data-ready="<?=!empty($r['quote_ready'])?'ready':'review'?>">
                    <td><input type="checkbox" class="row-check package-check" name="selected[]" value="<?=(int)$r['id']?>" aria-label="Select <?=sppE($r['supplier_package_code']?:$r['contract_code'])?>"></td>
                    <td>
                        <span class="spp-pkg-code"><?=sppE($r['supplier_package_code']?:$r['contract_code'])?></span>
                        <span class="spp-pkg-title"><?=sppE($r['supplier_package_title']?:$r['contract_name'])?></span>
                        <small class="spp-row-sub"><?=sppE($r['company_name'])?><?php if(($r['status']??'')!=='active'):?> · <?=sppE(ucfirst((string)($r['status']??'Inactive')))?><?php endif;?></small>
                    </td>
                    <td><span class="spp-destination"><i class="fa-solid fa-location-dot" style="color:#3891e2" aria-hidden="true"></i> <?=sppE($r['destination_name']?:'—')?></span></td>
                    <td><span class="spp-duration"><?=(int)$r['duration_nights']?>N / <?=(int)$r['duration_days']?>D</span></td>
                    <td><div class="spp-rate-cells"><span><strong><?=(int)$r['season_count']?></strong><small>Seasons</small></span><span><strong><?=(int)$r['category_count']?></strong><small>Categories</small></span><span><strong><?=(int)$r['active_pax_rows']?></strong><small>PAX rows</small></span></div></td>
                    <td><span class="spp-badge <?=!empty($r['quote_ready'])?'':'warn'?>"><i class="fa-solid <?=!empty($r['quote_ready'])?'fa-circle-check':'fa-triangle-exclamation'?>" aria-hidden="true"></i> <?=!empty($r['quote_ready'])?'Ready now':'Check rates'?></span></td>
                    <td><span class="spp-links"><?=(int)$r['travscope_links']?> link(s)</span><?php if((int)$block['package_links']>0):?><span class="spp-link-note">Will unlink on delete</span><?php endif;?><?php if($protected):?><span class="spp-link-note protected">History protected</span><?php endif;?></td>
                    <td><div class="spp-row-actions">
                        <a class="spp-btn primary" href="admin-supplier-package-manual.php?id=<?=(int)$r['id']?>" aria-label="Manage <?=sppE($r['supplier_package_code']?:$r['contract_code'])?>"><i class="fa-solid fa-pen" aria-hidden="true"></i> Manage</a>
                        <a class="spp-btn" href="admin-package-import-supplier.php?supplier_id=<?=(int)$r['supplier_id']?>&amp;contract_id=<?=(int)$r['id']?>" title="Use this supplier package in Package Master"><i class="fa-solid fa-link" aria-hidden="true"></i> Package Master</a>
                        <button type="submit" class="spp-btn danger" name="delete_single" value="<?=(int)$r['id']?>" aria-label="Delete <?=sppE($r['supplier_package_code']?:$r['contract_code'])?>"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
                    </div></td>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
        <div class="spp-remaining"><span id="sppVisibleCount"><?=count($rows)?></span> of <?=count($rows)?> packages in the current supplier/search selection. Permanent deletion is blocked for packages with quotation/costing/operations history.</div>
    </form>
    <?php endif;?>
</section>
<?php endif;?>
</div></div></main>
<script src="assets/travscope-pro-admin.js?v=20260924-v23" defer></script><script src="site-brand-sync.js?v=20261005-v2473" defer></script>
<script>
(function(){
    'use strict';
    const table=document.getElementById('sppPackageRows');
    if(!table)return;
    const rows=[...table.querySelectorAll('tr[data-ready]')];
    const checks=[...table.querySelectorAll('.package-check')];
    const bulkSelect=document.getElementById('selectAllPackages');
    const headSelect=document.getElementById('selectAllPackagesHead');
    const count=document.getElementById('selectedPackageCount');
    const deleteButton=document.getElementById('massDeleteBtn');
    const packageMasterButton=document.getElementById('bulkPackageMasterBtn');
    const visibleCount=document.getElementById('sppVisibleCount');
    const tabs=[...document.querySelectorAll('.spp-tab[data-filter]')];
    function visibleRows(){return rows.filter(row=>!row.hidden)}
    function sync(){
        const visible=visibleRows();
        const available=visible.map(row=>row.querySelector('.package-check')).filter(Boolean);
        const selected=checks.filter(el=>el.checked).length;
        if(count)count.textContent=selected+' selected';
        if(deleteButton)deleteButton.disabled=selected===0;
        if(packageMasterButton)packageMasterButton.disabled=selected===0;
        for(const checkbox of [bulkSelect,headSelect]){
            if(!checkbox)continue;
            const total=available.filter(el=>el.checked).length;
            checkbox.checked=available.length>0&&total===available.length;
            checkbox.indeterminate=total>0&&total<available.length;
        }
        rows.forEach(row=>row.classList.toggle('is-selected',!!row.querySelector('.package-check:checked')));
        if(visibleCount)visibleCount.textContent=String(visible.length);
    }
    function selectVisible(value){visibleRows().forEach(row=>{const checkbox=row.querySelector('.package-check');if(checkbox)checkbox.checked=value;});sync()}
    bulkSelect&&bulkSelect.addEventListener('change',()=>selectVisible(bulkSelect.checked));
    headSelect&&headSelect.addEventListener('change',()=>selectVisible(headSelect.checked));
    checks.forEach(el=>el.addEventListener('change',sync));
    tabs.forEach(tab=>tab.addEventListener('click',()=>{
        const filter=tab.dataset.filter||'all';
        tabs.forEach(other=>{const isActive=other===tab;other.classList.toggle('is-active',isActive);other.setAttribute('aria-pressed',String(isActive));});
        rows.forEach(row=>{row.hidden=filter!=='all'&&row.dataset.ready!==filter;if(row.hidden){const checkbox=row.querySelector('.package-check');if(checkbox)checkbox.checked=false;}});
        sync();
    }));
    if(packageMasterButton)packageMasterButton.addEventListener('click',(event)=>{
        const selected=checks.filter(el=>el.checked).length;
        if(selected<1){event.preventDefault();return;}
        if(!window.confirm('Create '+selected+' selected supplier package'+(selected===1?'':'s')+' as Drafts in Package Master? Existing linked packages will be skipped and nothing will be overwritten.'))event.preventDefault();
    });
    if(deleteButton)deleteButton.addEventListener('click',(event)=>{
        const selected=checks.filter(el=>el.checked).length;
        if(selected<1){event.preventDefault();return;}
        if(!window.confirm('Permanently delete '+selected+' selected supplier package'+(selected===1?'':'s')+'? Protected history cannot be deleted.'))event.preventDefault();
    });
    sync();
})();
</script>
</body>
</html>
