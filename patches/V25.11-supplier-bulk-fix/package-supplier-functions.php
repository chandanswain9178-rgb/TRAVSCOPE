<?php
declare(strict_types=1);

/* TRAVSCOPE V20 - Package ↔ Supplier sourcing connection helpers */

function tsPSOTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        return $cache[$table] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function tsPSOColumnExists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `" . str_replace('`','',$table) . "` LIKE " . $pdo->quote($column));
        return $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function tsPSOReady(PDO $pdo): bool
{
    return tsPSOTableExists($pdo, 'package_supplier_options')
        && tsPSOColumnExists($pdo, 'packages', 'source_type')
        && tsPSOColumnExists($pdo, 'packages', 'source_itinerary_id')
        && tsPSOColumnExists($pdo, 'package_supplier_options', 'contract_id')
        && tsPSOColumnExists($pdo, 'supplier_contracts', 'record_type')
        && tsPSOColumnExists($pdo, 'supplier_contracts', 'entry_mode');
}

function tsPSONormalizeCode(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9]+/', '-', $value) ?? $value;
    return trim($value, '-');
}

function tsPSONextPackageCode(PDO $pdo): string
{
    $prefix = 'PKG-' . date('ymd') . '-';
    for ($i=0; $i<50; $i++) {
        $code = $prefix . strtoupper(bin2hex(random_bytes(2)));
        $s = $pdo->prepare('SELECT id FROM packages WHERE package_code=? LIMIT 1');
        $s->execute([$code]);
        if (!$s->fetchColumn()) return $code;
    }
    return $prefix . strtoupper(bin2hex(random_bytes(4)));
}

function tsPSOSlug(string $text): string
{
    $text = trim($text);
    if (function_exists('iconv')) {
        $x = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($x !== false) $text = $x;
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? $text;
    return trim($text, '-') ?: 'package';
}

function tsPSOUniqueSlug(PDO $pdo, string $base): string
{
    $slug = $base ?: 'package';
    $i = 2;
    while (true) {
        $s = $pdo->prepare('SELECT id FROM packages WHERE slug=? LIMIT 1');
        $s->execute([$slug]);
        if (!$s->fetchColumn()) return $slug;
        $slug = $base . '-' . $i++;
    }
}

function tsPSOPackage(PDO $pdo, int $packageId): ?array
{
    $s=$pdo->prepare("SELECT p.*,d.name destination_name,d.country destination_country FROM packages p LEFT JOIN destinations d ON d.id=p.destination_id WHERE p.id=? LIMIT 1");
    $s->execute([$packageId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function tsPSOOptions(PDO $pdo, int $packageId): array
{
    if (!tsPSOTableExists($pdo,'package_supplier_options')) return [];
    $hasContractId=tsPSOColumnExists($pdo,'package_supplier_options','contract_id');
    $contractExpr=$hasContractId?'COALESCE(o.contract_id,v.contract_id)':'v.contract_id';
    $sql="SELECT o.*,s.company_name,s.supplier_code,s.supplier_type,s.priority supplier_priority,
                 i.title supplier_package_title,i.duration_nights supplier_duration_nights,i.duration_days supplier_duration_days,
                 i.supplier_package_code,i.meal_plan supplier_meal_plan,i.status itinerary_status,
                 v.season_name,v.season_type,v.rate_priority,v.valid_from version_valid_from,v.valid_to version_valid_to,v.status version_status,
                 c.id source_contract_id,c.contract_code,c.contract_name,c.status contract_status,c.record_type,c.entry_mode,
                 (SELECT COUNT(*) FROM supplier_contract_rate_groups g WHERE g.contract_id={$contractExpr} AND g.status='active') active_rate_groups,
                 (SELECT COUNT(*) FROM supplier_contract_pax_rates pr JOIN supplier_contract_rate_groups g2 ON g2.id=pr.rate_group_id WHERE g2.contract_id={$contractExpr} AND pr.status='active') active_pax_rows
          FROM package_supplier_options o
          JOIN suppliers s ON s.id=o.supplier_id
          LEFT JOIN supplier_contract_itineraries i ON i.id=o.itinerary_id
          LEFT JOIN supplier_contract_versions v ON v.id=o.contract_version_id
          LEFT JOIN supplier_contracts c ON c.id={$contractExpr}
          WHERE o.package_id=?
          ORDER BY o.is_primary DESC,CASE o.relationship_type WHEN 'primary_dmc' THEN 1 WHEN 'alternate_dmc' THEN 2 ELSE 3 END,
                   CASE s.priority WHEN 'preferred' THEN 1 WHEN 'regular' THEN 2 ELSE 3 END,s.company_name,o.id";
    $st=$pdo->prepare($sql);$st->execute([$packageId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tsPSOLink(PDO $pdo, int $packageId, int $itineraryId, string $relationshipType, bool $isPrimary, int $adminId, ?string $notes=null): int
{
    if (!tsPSOReady($pdo)) throw new RuntimeException('Install the V20 Package Supplier SQL first.');
    if (!in_array($relationshipType,['primary_dmc','alternate_dmc','component'],true)) $relationshipType='alternate_dmc';
    $st=$pdo->prepare("SELECT i.id,i.supplier_id,i.contract_version_id,i.contract_id,i.supplier_package_code,i.source_heading,s.status supplier_status,v.status version_status,c.status contract_status,c.record_type,c.entry_mode
                      FROM supplier_contract_itineraries i
                      JOIN suppliers s ON s.id=i.supplier_id
                      JOIN supplier_contract_versions v ON v.id=i.contract_version_id
                      JOIN supplier_contracts c ON c.id=i.contract_id
                      WHERE i.id=? LIMIT 1");
    $st->execute([$itineraryId]);$it=$st->fetch(PDO::FETCH_ASSOC);
    if(!$it) throw new RuntimeException('Supplier package was not found.');
    if($isPrimary){$pdo->prepare("UPDATE package_supplier_options SET is_primary=0,relationship_type=IF(relationship_type='primary_dmc','alternate_dmc',relationship_type),updated_by_admin_id=?,updated_at=NOW() WHERE package_id=?")->execute([$adminId?:null,$packageId]);$relationshipType='primary_dmc';}
    $code=trim((string)($it['supplier_package_code']??''));if($code==='')$code=tsPSONormalizeCode((string)($it['source_heading']??''));
    $hasContractId=tsPSOColumnExists($pdo,'package_supplier_options','contract_id');
    $manualContract=($hasContractId && ($it['record_type']??'')==='package' && ($it['entry_mode']??'')==='manual')?(int)$it['contract_id']:null;
    if($manualContract){$find=$pdo->prepare('SELECT id FROM package_supplier_options WHERE package_id=? AND supplier_id=? AND contract_id=? LIMIT 1');$find->execute([$packageId,(int)$it['supplier_id'],$manualContract]);}
    else{$find=$pdo->prepare('SELECT id FROM package_supplier_options WHERE package_id=? AND supplier_id=? AND itinerary_id=? LIMIT 1');$find->execute([$packageId,(int)$it['supplier_id'],$itineraryId]);}
    $id=(int)($find->fetchColumn()?:0);
    if($id>0){
        if($hasContractId){$pdo->prepare("UPDATE package_supplier_options SET contract_id=?,contract_version_id=?,itinerary_id=?,supplier_package_code=?,relationship_type=?,is_primary=?,enabled_for_quotation=1,status='active',notes=?,updated_by_admin_id=?,updated_at=NOW() WHERE id=?")
            ->execute([$manualContract,(int)$it['contract_version_id'],$itineraryId,$code?:null,$relationshipType,$isPrimary?1:0,$notes,$adminId?:null,$id]);}
        else{$pdo->prepare("UPDATE package_supplier_options SET contract_version_id=?,itinerary_id=?,supplier_package_code=?,relationship_type=?,is_primary=?,enabled_for_quotation=1,status='active',notes=?,updated_by_admin_id=?,updated_at=NOW() WHERE id=?")
            ->execute([(int)$it['contract_version_id'],$itineraryId,$code?:null,$relationshipType,$isPrimary?1:0,$notes,$adminId?:null,$id]);}
        return $id;
    }
    if($hasContractId){$pdo->prepare("INSERT INTO package_supplier_options(package_id,supplier_id,contract_id,contract_version_id,itinerary_id,supplier_package_code,relationship_type,is_primary,enabled_for_quotation,status,notes,created_by_admin_id,updated_by_admin_id) VALUES(?,?,?,?,?,?,?,?,1,'active',?,?,?)")
        ->execute([$packageId,(int)$it['supplier_id'],$manualContract,(int)$it['contract_version_id'],$itineraryId,$code?:null,$relationshipType,$isPrimary?1:0,$notes,$adminId?:null,$adminId?:null]);}
    else{$pdo->prepare("INSERT INTO package_supplier_options(package_id,supplier_id,contract_version_id,itinerary_id,supplier_package_code,relationship_type,is_primary,enabled_for_quotation,status,notes,created_by_admin_id,updated_by_admin_id) VALUES(?,?,?,?,?,?,?,1,'active',?,?,?)")
        ->execute([$packageId,(int)$it['supplier_id'],(int)$it['contract_version_id'],$itineraryId,$code?:null,$relationshipType,$isPrimary?1:0,$notes,$adminId?:null,$adminId?:null]);}
    return (int)$pdo->lastInsertId();
}

function tsPSOSetPrimary(PDO $pdo, int $packageId, int $optionId, int $adminId): void
{
    if(!tsPSOTableExists($pdo,'package_supplier_options'))throw new RuntimeException('V20 Package Supplier SQL is not installed.');
    $pdo->beginTransaction();
    try{
        $pdo->prepare("UPDATE package_supplier_options SET is_primary=0,relationship_type=IF(relationship_type='primary_dmc','alternate_dmc',relationship_type),updated_by_admin_id=?,updated_at=NOW() WHERE package_id=?")->execute([$adminId?:null,$packageId]);
        $u=$pdo->prepare("UPDATE package_supplier_options SET is_primary=1,relationship_type='primary_dmc',status='active',enabled_for_quotation=1,updated_by_admin_id=?,updated_at=NOW() WHERE id=? AND package_id=?");
        $u->execute([$adminId?:null,$optionId,$packageId]);
        if($u->rowCount()===0)throw new RuntimeException('Supplier option not found.');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}


/**
 * V24.12 - Build polished customer-facing Package Master content from the
 * linked supplier package. The text is derived only from the saved supplier
 * package, route and day-wise itinerary; no new attractions or commercial
 * promises are invented.
 */
function tsPSOCustomerText(string $value): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/ *\n */u', "\n", $value) ?? $value;
    $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value;
    return trim($value);
}

function tsPSOUniqueCustomerItems(array $items): array
{
    $out=[];$seen=[];
    foreach($items as $item){
        $item=tsPSOCustomerText((string)$item);
        if($item==='')continue;
        $key=function_exists('mb_strtolower')?mb_strtolower($item,'UTF-8'):strtolower($item);
        if(isset($seen[$key]))continue;
        $seen[$key]=true;$out[]=$item;
    }
    return $out;
}

function tsPSOTrimCustomerText(string $text, int $limit): string
{
    $text=tsPSOCustomerText($text);
    if($limit<=0)return $text;
    $len=function_exists('mb_strlen')?mb_strlen($text,'UTF-8'):strlen($text);
    if($len<=$limit)return $text;
    $cut=function_exists('mb_substr')?mb_substr($text,0,$limit-1,'UTF-8'):substr($text,0,$limit-1);
    $cut=preg_replace('/\s+\S*$/u','',$cut)??$cut;
    return rtrim($cut," ,.;:-").'…';
}

function tsPSOWordCount(string $text): int
{
    $text=tsPSOCustomerText($text);
    if($text==='' || !preg_match_all('/\S+/u',$text,$m))return 0;
    return count($m[0]);
}

function tsPSOWordLimit(string $text, int $limit): string
{
    $text=tsPSOCustomerText($text);
    if($text==='' || $limit<=0)return $text;
    if(!preg_match_all('/\S+/u',$text,$m,PREG_OFFSET_CAPTURE) || count($m[0])<=$limit)return $text;
    $last=$m[0][$limit-1];$end=(int)$last[1]+strlen((string)$last[0]);
    return rtrim(substr($text,0,$end)," \t\n\r\0\x0B,;:-").'…';
}

function tsPSOFitWordRange(string $text, int $min, int $max, array $supplements=[]): string
{
    $text=tsPSOCustomerText($text);
    $initial=tsPSOWordCount($text);
    if($initial>$max)return tsPSOWordLimit($text,$max);
    foreach($supplements as $sentence){
        $current=tsPSOWordCount($text);if($current>=$min)break;
        $sentence=tsPSOCustomerText((string)$sentence);if($sentence==='')continue;
        if($current+tsPSOWordCount($sentence)<=$max)$text=trim($text.($text!==''?' ':'').$sentence);
    }
    if(tsPSOWordCount($text)<$min){
        $fillers=[
            'This keeps the summary clear.',
            'This keeps the package summary clear and factual.',
            'This structure keeps the package summary clear, factual and easy to review.',
            'This structure keeps the confirmed package information clear, factual and easy for the traveller to review.',
            'This final context keeps the confirmed package information clear, factual and professionally structured for review before booking.'
        ];
        $current=tsPSOWordCount($text);$best='';$bestProjected=9999;
        foreach($fillers as $f){$f=tsPSOCustomerText($f);$projected=$current+tsPSOWordCount($f);if($projected>=$min&&$projected<=$max&&$projected<$bestProjected){$best=$f;$bestProjected=$projected;}}
        if($best!=='')$text=trim($text.($text!==''?' ':'').$best);
    }
    return tsPSOWordLimit($text,$max);
}

function tsPSOCleanHighlightTitle(string $title): string
{
    $title=tsPSOCustomerText($title);
    $title=preg_replace('/^day\s*0*\d+\s*[:\-–—]?\s*/iu','',$title)??$title;
    $title=preg_replace('/\s*\([^)]*(?:\bkm\b|\bkms\b|\bhrs?\b|\bhours?\b|one\s*way)[^)]*\)\s*/iu',' ',$title)??$title;
    $title=str_ireplace(['Rly Station','GuwahatiAirport','IndoBangladeshBorder','BangladeshBorder'],['Railway Station','Guwahati Airport','Indo–Bangladesh Border','Bangladesh Border'],$title);
    return trim(preg_replace('/\s{2,}/u',' ',$title)??$title," -–—\t\n\r\0\x0B");
}

function tsPSOBuildCustomerContent(array $data): array
{
    $it=$data['itinerary']??[];$days=$data['days']??[];
    $title=tsPSOCustomerText((string)($it['title']??''));
    if($title==='')$title=tsPSOCustomerText((string)($it['supplier_package_code']??''));
    if($title==='')$title='Travel Package';
    $nights=max(0,(int)($it['duration_nights']??0));$dayCount=max(0,(int)($it['duration_days']??0));
    $duration=($nights>0||$dayCount>0)?trim(($nights>0?$nights.' Nights':'').(($nights>0&&$dayCount>0)?' / ':'').($dayCount>0?$dayCount.' Days':'')):'';
    $destination=tsPSOCustomerText((string)($it['destination_name']??''));

    $overnights=[];$dayTitles=[];
    foreach($days as $day){
        $city=tsPSOCustomerText((string)($day['overnight_city']??''));
        if($city!=='')$overnights[]=$city;
        $dt=tsPSOCleanHighlightTitle((string)($day['day_title']??''));
        if($dt!=='')$dayTitles[]=$dt;
    }
    $overnights=tsPSOUniqueCustomerItems($overnights);
    $dayTitles=tsPSOUniqueCustomerItems($dayTitles);
    $marketingTitles=array_values(array_filter($dayTitles,static function(string $v):bool{
        $low=function_exists('mb_strtolower')?mb_strtolower($v,'UTF-8'):strtolower($v);
        $routine=(bool)preg_match('/\b(airport|railway|rly\s*station|departure|drop)\b/u',$low);
        $experience=(bool)preg_match('/\b(sightseeing|excursion|safari|falls?|waterfall|cave|temple|lake|dawki|mawlynnong|cherrapunji|tawang|kaziranga|bumla|cruise|trek|park|museum|monastery|sela|dirang|bomdila)\b/u',$low);
        return !$routine || $experience;
    }));
    if(!$marketingTitles)$marketingTitles=$dayTitles;
    $place=$destination!==''?$destination:($overnights[0]??'North-East India');

    $short='';
    if($duration!=='' && $place!=='')$short='Discover '.$place.' on a well-paced '.$duration.' journey designed around scenic transfers, meaningful sightseeing and comfortable overnight stops.';
    elseif($duration!=='')$short='Enjoy a well-paced '.$duration.' journey with a clearly structured sightseeing and transfer programme.';
    else $short='A thoughtfully structured travel package with a detailed day-wise itinerary.';
    if($marketingTitles){
        $sample=array_slice($marketingTitles,0,3);
        $short.=' Highlights include '.implode(', ',$sample).'.';
    }
    $short=tsPSOFitWordRange($short,90,100,[
        'The programme follows the supplier-confirmed day sequence, keeping transfers, sightseeing and overnight stops in a logical order so travellers can understand how each stage connects with the next.',
        'Route details, approximate travel information and operational notes are presented transparently, while optional or condition-based activities remain governed by the supplier’s stated terms and local operating requirements.',
        'Together, these details provide a concise professional overview before the full itinerary.'
    ]);

    $paragraphs=[];
    $opening='Experience '.($place!==''?$place:$title);
    if($duration!=='')$opening.=' through this '.$duration.' itinerary';
    $opening.=', carefully arranged around the supplier’s confirmed routing, sightseeing sequence and overnight plan.';
    $paragraphs[]=$opening;
    if(count($overnights)>1){
        $route=$overnights;$last=array_pop($route);$routeText=$route?implode(', ',$route).' and '.$last:$last;
        $paragraphs[]='The journey progresses through '.$routeText.', balancing road travel with the key experiences scheduled for each destination.';
    }
    if($marketingTitles){
        $sample=array_slice($marketingTitles,0,6);
        $paragraphs[]='Featured experiences include '.implode('; ',$sample).'.';
    }
    $paragraphs[]='Each day below presents the travel flow in a formal, easy-to-read format, including the main route, sightseeing, approximate travel information where supplied, and the overnight destination. Optional, permit-based and chargeable activities remain subject to the supplier’s stated conditions.';
    $full=implode("\n\n",tsPSOUniqueCustomerItems($paragraphs));
    $full=tsPSOFitWordRange($full,190,200,[
        'The presentation follows the confirmed chronology of the supplier itinerary rather than adding attractions or services that are not recorded in the source package.',
        'Transfers, sightseeing periods and overnight locations are therefore described as connected stages of one journey, allowing the traveller to assess the practical flow as well as the experiential sequence.',
        'Where the supplier has provided distances, travel times, meal information or route notes, those details remain associated with the relevant day so the programme can be read with appropriate operational context.',
        'Hotel categories, inclusions, exclusions, payment conditions and cancellation provisions should be considered together with this narrative because they define the commercial scope separately from the sightseeing description.',
        'Relevant supplier conditions remain applicable to services that depend on availability or local requirements.',
        'This structure keeps the package concise, factual and easy to review before booking.',
        'The result is a structured package summary intended for travellers who prefer precise information, clear sequencing and a professional explanation of what the confirmed itinerary actually contains.'
    ]);

    $highlights=[];
    if($duration!=='' && $place!=='')$highlights[]=$duration.' journey through '.$place;
    foreach($marketingTitles as $dt)$highlights[]=$dt;
    if(!$marketingTitles){foreach($overnights as $city)$highlights[]='Overnight stay at '.$city;}
    $highlights=array_slice(tsPSOUniqueCustomerItems($highlights),0,16);

    // V24.14: preserve explicit customer-facing content imported from the workbook.
    $override=is_array($data['content_override']??null)?$data['content_override']:[];
    $overrideShort=trim((string)($override['short_description']??''));
    $overrideFull=trim((string)($override['description']??''));
    $overrideHighlights=trim((string)($override['tour_highlights']??''));
    if($overrideShort!=='')$short=$overrideShort;
    if($overrideFull!=='')$full=$overrideFull;
    $short=tsPSOFitWordRange($short,90,100,[
        'The programme follows the confirmed day-wise route, keeping transfers, sightseeing and overnight stops in a logical sequence for clear pre-travel understanding.',
        'Operational details remain tied to the recorded supplier itinerary, while optional or conditional services continue to follow the stated package terms.',
        'Together, these points provide a concise professional overview before the detailed itinerary.',
        'The summary is written to help the traveller understand the journey structure, confirmed routing and service context without adding unverified attractions or assumptions beyond the supplier record.'
    ]);
    $full=tsPSOFitWordRange($full,190,200,[
        'The description follows the confirmed supplier chronology and does not introduce attractions, services or commercial promises that are absent from the saved package information.',
        'Transfers, sightseeing periods and overnight locations are presented as connected stages so the traveller can evaluate both the practical movement and the intended experience.',
        'Where supplied, distance, travel-time, meal and route information remains linked to the relevant day for clearer operational understanding.',
        'Hotel categories, inclusions, exclusions, payment conditions and cancellation provisions should be read together with this narrative because they define the commercial scope separately from the sightseeing plan.',
        'Relevant supplier conditions remain applicable to services that depend on availability or local requirements.',
        'The result is a structured summary for travellers who prefer precise sequencing, transparent context and a professional explanation of the confirmed itinerary.',
        'This approach keeps the narrative informative while preserving a clear distinction between the travel experience, operational notes and the separate commercial terms of the package.',
        'Accordingly, the customer can review the journey with a realistic understanding of what has been confirmed and what remains subject to supplier or local operating conditions.'
    ]);
    if($overrideHighlights!=='')return ['short_description'=>$short,'description'=>$full,'tour_highlights'=>$overrideHighlights];

    return ['short_description'=>$short,'description'=>$full,'tour_highlights'=>implode("\n",$highlights)];
}

function tsPSOBuildPackageTerms(array $data): string
{
    $override=is_array($data['content_override']??null)?$data['content_override']:[];
    $overrideTerms=trim((string)($override['package_terms']??''));
    if($overrideTerms!=='')return $overrideTerms;
    $it=$data['itinerary']??[];$policy=$data['policy']??[];
    $sections=[];
    $add=function(string $label,array $items)use(&$sections):void{
        $items=tsPSOUniqueCustomerItems($items);
        if(!$items)return;
        $sections[]=$label.":\n".implode("\n",array_map(static fn($x)=>'• '.$x,$items));
    };
    $add('Payment Terms',array_merge([(string)($it['version_payment_terms']??''),(string)($it['contract_payment_terms']??'')],$policy['payment_terms']??[]));
    $add('Cancellation Terms',[(string)($it['version_cancellation_terms']??''),(string)($it['contract_cancellation_terms']??'')]);
    $add('Transport Terms',[(string)($it['version_transport_terms']??'')]);
    $add('Hotel / Availability Policy',$policy['hotel_policy']??[]);
    $add('Child Policy',$policy['child_policy']??[]);
    $add('ID / Document Policy',$policy['id_policy']??[]);
    $add('Operational Notes',$policy['operational_note']??[]);
    $add('Other Terms',$policy['other']??[]);
    return trim(implode("\n\n",$sections));
}

function tsPSOImportPayload(PDO $pdo, int $itineraryId): array
{
    $s=$pdo->prepare("SELECT i.*,s.company_name,s.supplier_code,s.supplier_type,s.status supplier_status,
                             c.contract_code,c.contract_name,c.status contract_status,c.record_type,
                             c.payment_terms contract_payment_terms,c.cancellation_terms contract_cancellation_terms,
                             v.id version_id,v.version_no,v.version_label,v.season_name,v.season_type,v.rate_priority,v.valid_from version_valid_from,v.valid_to version_valid_to,v.status version_status,
                             v.payment_terms version_payment_terms,v.cancellation_terms version_cancellation_terms,v.transport_terms version_transport_terms,
                             d.name destination_name,d.country destination_country
                      FROM supplier_contract_itineraries i
                      JOIN suppliers s ON s.id=i.supplier_id
                      JOIN supplier_contract_versions v ON v.id=i.contract_version_id
                      JOIN supplier_contracts c ON c.id=i.contract_id
                      LEFT JOIN destinations d ON d.id=i.destination_id
                      WHERE i.id=? LIMIT 1");
    $s->execute([$itineraryId]);$it=$s->fetch(PDO::FETCH_ASSOC);
    if(!$it)throw new RuntimeException('Supplier package was not found.');
    $days=[];
    if(tsPSOTableExists($pdo,'supplier_contract_day_itineraries')){
        $d=$pdo->prepare("SELECT day_number,day_title,day_description,overnight_city FROM supplier_contract_day_itineraries WHERE itinerary_id=? AND status IN ('active','review') ORDER BY day_number,id");
        $d->execute([$itineraryId]);$days=$d->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
    $policy=['inclusions'=>[],'exclusions'=>[],'payment_terms'=>[],'hotel_policy'=>[],'child_policy'=>[],'id_policy'=>[],'operational_note'=>[],'other'=>[]];
    $contentOverride=['short_description'=>'','description'=>'','tour_highlights'=>'','package_terms'=>''];
    if(tsPSOTableExists($pdo,'supplier_contract_policy_items')){
        $p=$pdo->prepare("SELECT policy_type,title,content,visibility FROM supplier_contract_policy_items WHERE contract_version_id=? AND status IN ('active','review') ORDER BY id");
        $p->execute([(int)$it['contract_version_id']]);
        foreach($p->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $type=(string)$row['policy_type'];$title=trim((string)($row['title']??''));$body=(string)($row['content']??'');
            if($type==='other'){
                if(strcasecmp($title,'TRAVSCOPE Package Short Description')===0){$contentOverride['short_description']=$body;continue;}
                if(strcasecmp($title,'TRAVSCOPE Package Full Description')===0){$contentOverride['description']=$body;continue;}
                if(strcasecmp($title,'TRAVSCOPE Tour Highlights')===0){$contentOverride['tour_highlights']=$body;continue;}
                if(strcasecmp($title,'TRAVSCOPE Package Terms & Conditions')===0){$contentOverride['package_terms']=$body;continue;}
            }
            if(isset($policy[$type]) && in_array((string)$row['visibility'],['customer','both'],true))$policy[$type][]=$body;
        }
    }
    $groups=[];
    $g=$pdo->prepare("SELECT g.*, (SELECT COUNT(*) FROM supplier_contract_pax_rates pr WHERE pr.rate_group_id=g.id AND pr.status='active') active_pax_rows FROM supplier_contract_rate_groups g WHERE g.itinerary_id=? AND g.status IN ('active','review') ORDER BY g.source_order,g.id");
    $g->execute([$itineraryId]);$groups=$g->fetchAll(PDO::FETCH_ASSOC)?:[];
    return ['itinerary'=>$it,'days'=>$days,'policy'=>$policy,'content_override'=>$contentOverride,'groups'=>$groups];
}


/**
 * Build non-overlapping Package Master reference price periods from the
 * supplier package's ACTIVE seasonal exact-PAX rate matrix.
 *
 * Package Master has no PAX dimension, therefore the conservative reference
 * is the smallest published exact PAX slab per mapped star category. When
 * multiple supplier categories map to the same star we keep the higher rate.
 * Supplier tax is added when the source rate is tax-exclusive.
 */
function tsPSOPackageMasterReferencePriceRows(PDO $pdo, int $contractId): array
{
    if ($contractId <= 0 || !tsPSOTableExists($pdo, 'package_date_prices')) return [];

    $sql = "SELECT
                v.id AS version_id,
                v.valid_from,
                v.valid_to,
                COALESCE(v.rate_priority,0) AS rate_priority,
                g.id AS group_id,
                g.mapped_star_category,
                g.supplier_category,
                COALESCE(g.tax_included,0) AS tax_included,
                COALESCE(g.tax_percent,0) AS tax_percent,
                pr.pax_min,
                pr.pax_max,
                pr.rate_per_person
            FROM supplier_contract_versions v
            JOIN supplier_contracts c ON c.id=v.contract_id AND c.status='active'
            JOIN suppliers s ON s.id=c.supplier_id AND s.status='active'
            JOIN supplier_contract_rate_groups g
              ON g.contract_version_id=v.id
             AND g.contract_id=v.contract_id
             AND g.status='active'
            JOIN supplier_contract_itineraries i
              ON i.id=g.itinerary_id
             AND i.contract_id=c.id
             AND i.contract_version_id=v.id
             AND i.supplier_id=s.id
             AND i.status='active'
            JOIN supplier_contract_pax_rates pr
              ON pr.rate_group_id=g.id
             AND pr.contract_version_id=v.id
             AND pr.status='active'
            WHERE v.contract_id=?
              AND v.status='active'
              AND v.valid_from IS NOT NULL
              AND v.valid_to IS NOT NULL
              AND v.valid_from<>''
              AND v.valid_to<>''
              AND pr.pax_min=pr.pax_max
              AND pr.pax_min>0
              AND pr.rate_per_person>0
            ORDER BY v.rate_priority DESC,v.id DESC,g.id,pr.pax_min ASC,pr.id ASC";
    $st=$pdo->prepare($sql);
    $st->execute([$contractId]);
    $raw=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    if(!$raw) return [];

    // version -> star -> smallest exact PAX -> conservative rate
    $versions=[];
    foreach($raw as $r){
        $vid=(int)$r['version_id'];
        $star=(int)$r['mapped_star_category'];
        if($star<3||$star>5)$star=tsPSOInferStarFromSupplierCategory((string)($r['supplier_category']??''));
        if($star<3||$star>5) continue;
        $pax=(int)$r['pax_min'];
        $rate=max(0.0,(float)$r['rate_per_person']);
        if(!(int)$r['tax_included'] && (float)$r['tax_percent']>0){
            $rate += $rate * (float)$r['tax_percent'] / 100;
        }
        if($rate<=0) continue;
        if(!isset($versions[$vid])){
            $versions[$vid]=[
                'id'=>$vid,
                'valid_from'=>(string)$r['valid_from'],
                'valid_to'=>(string)$r['valid_to'],
                'priority'=>(int)$r['rate_priority'],
                'stars'=>[]
            ];
        }
        if(!isset($versions[$vid]['stars'][$star])){
            $versions[$vid]['stars'][$star]=['pax'=>$pax,'rate'=>$rate];
        }else{
            $cur=$versions[$vid]['stars'][$star];
            if($pax < (int)$cur['pax']){
                $versions[$vid]['stars'][$star]=['pax'=>$pax,'rate'=>$rate];
            }elseif($pax === (int)$cur['pax'] && $rate > (float)$cur['rate']){
                // More than one supplier category may map to the same star.
                $versions[$vid]['stars'][$star]=['pax'=>$pax,'rate'=>$rate];
            }
        }
    }
    $versions=array_values(array_filter($versions,static fn($v)=>!empty($v['stars'])));
    if(!$versions) return [];

    // Split time into atomic non-overlapping periods, then let highest priority win.
    $bounds=[];
    foreach($versions as $v){
        try{
            $from=new DateTimeImmutable($v['valid_from']);
            $to=new DateTimeImmutable($v['valid_to']);
            if($to<$from) continue;
            $bounds[$from->format('Y-m-d')]=true;
            $bounds[$to->modify('+1 day')->format('Y-m-d')]=true;
        }catch(Throwable $ignored){}
    }
    $dates=array_keys($bounds); sort($dates);
    if(count($dates)<2) return [];

    $out=[];
    for($i=0;$i<count($dates)-1;$i++){
        $from=$dates[$i];
        $to=(new DateTimeImmutable($dates[$i+1]))->modify('-1 day')->format('Y-m-d');
        if($to<$from) continue;
        $candidates=[];
        foreach($versions as $v){
            if($v['valid_from'] <= $from && $v['valid_to'] >= $to) $candidates[]=$v;
        }
        if(!$candidates) continue;
        usort($candidates,static function($a,$b){
            $p=((int)$b['priority'])<=>((int)$a['priority']);
            return $p!==0?$p:(((int)$b['id'])<=>((int)$a['id']));
        });
        $win=$candidates[0];
        $row=[
            'valid_from'=>$from,
            'valid_to'=>$to,
            'price_3_star'=>isset($win['stars'][3])?round((float)$win['stars'][3]['rate'],2):null,
            'price_4_star'=>isset($win['stars'][4])?round((float)$win['stars'][4]['rate'],2):null,
            'price_5_star'=>isset($win['stars'][5])?round((float)$win['stars'][5]['rate'],2):null,
            'reference_pax'=>array_values(array_unique(array_map(static fn($x)=>(int)$x['pax'],$win['stars']))),
            'source_version_id'=>(int)$win['id'],
            'source_priority'=>(int)$win['priority'],
        ];
        if($row['price_3_star']===null && $row['price_4_star']===null && $row['price_5_star']===null) continue;

        // Merge adjacent periods when the effective customer-reference prices are identical.
        if($out){
            $last=&$out[count($out)-1];
            $adj=(new DateTimeImmutable($last['valid_to']))->modify('+1 day')->format('Y-m-d')===$row['valid_from'];
            $same=$last['price_3_star']===$row['price_3_star'] && $last['price_4_star']===$row['price_4_star'] && $last['price_5_star']===$row['price_5_star'];
            if($adj && $same){$last['valid_to']=$row['valid_to']; unset($last); continue;}
            unset($last);
        }
        $out[]=$row;
    }
    return $out;
}

function tsPSOInferStarFromSupplierCategory(string $supplierCategory): int
{
    $n=strtolower(trim($supplierCategory));
    if($n==='') return 0;
    if(preg_match('/\b5\s*star\b|premium|luxury|luxurious|suite/i',$n)) return 5;
    if(preg_match('/\b4\s*star\b|super\s*deluxe|superdeluxe|superior|executive|boutique/i',$n)) return 4;
    if(preg_match('/\b3\s*star\b|deluxe|standard|std\b/i',$n)) return 3;
    if(preg_match('/\b2\s*star\b|budget|economy/i',$n)) return 2;
    return 0;
}

function tsPSODestinationCoverImage(PDO $pdo, int $destinationId): string
{
    if($destinationId<=0 || !tsPSOTableExists($pdo,'destinations')) return '';
    try{
        $cols=[];
        foreach(['image','banner_image'] as $col){if(tsPSOColumnExists($pdo,'destinations',$col))$cols[]=$col;}
        if(!$cols)return '';
        $q=$pdo->prepare('SELECT '.implode(',',$cols).' FROM destinations WHERE id=? LIMIT 1');
        $q->execute([$destinationId]);
        $row=$q->fetch(PDO::FETCH_ASSOC)?:[];
        foreach(['image','banner_image'] as $col){$v=trim((string)($row[$col]??''));if($v!=='')return $v;}
    }catch(Throwable $ignored){}
    return '';
}

function tsPSOInsertPackageMasterReferencePrices(PDO $pdo, int $packageId, int $contractId): int
{
    if($packageId<=0 || $contractId<=0 || !tsPSOTableExists($pdo,'package_date_prices')) return 0;
    $rows=tsPSOPackageMasterReferencePriceRows($pdo,$contractId);
    if(!$rows) return 0;
    $ins=$pdo->prepare("INSERT INTO package_date_prices(package_id,valid_from,valid_to,price_3_star,price_4_star,price_5_star,sort_order) VALUES(?,?,?,?,?,?,?)");
    $sort=1;
    foreach($rows as $r){
        $ins->execute([$packageId,$r['valid_from'],$r['valid_to'],$r['price_3_star'],$r['price_4_star'],$r['price_5_star'],$sort++]);
    }
    return count($rows);
}

function tsPSOCreatePackageFromSupplier(PDO $pdo, int $itineraryId, int $adminId, bool $allowDraftSource=false): int
{
    if(!tsPSOReady($pdo))throw new RuntimeException('Install the V20 Package Supplier SQL first.');
    $data=tsPSOImportPayload($pdo,$itineraryId);$it=$data['itinerary'];
    $sourceActive=(string)($it['status']??'')==='active'&&(string)($it['version_status']??'')==='active'&&(string)($it['contract_status']??'')==='active';
    if((string)($it['supplier_status']??'')!=='active')throw new RuntimeException('Only packages from an active supplier can be imported.');
    if($allowDraftSource){
        if((string)($it['record_type']??'')!=='package'||!in_array((string)($it['contract_status']??''),['active','draft'],true)||!in_array((string)($it['status']??''),['active','review'],true)||!in_array((string)($it['version_status']??''),['active','review'],true))throw new RuntimeException('Only active or review supplier package content can be copied into a draft.');
    }elseif(!$sourceActive)throw new RuntimeException('Only an ACTIVE supplier package from an ACTIVE Rate Book can be imported.');
    $title=trim((string)$it['title']);if($title==='')$title=trim((string)$it['supplier_package_code']);if($title==='')$title='Imported Travel Package';
    $code=tsPSONextPackageCode($pdo);$slug=tsPSOUniqueSlug($pdo,tsPSOSlug($title));
    $currency=strtoupper(trim((string)$it['currency']))?:'INR';
    $inclusions=implode("\n",tsPSOUniqueCustomerItems(array_merge([(string)($it['inclusions']??'')],$data['policy']['inclusions'])));
    $exclusions=implode("\n",tsPSOUniqueCustomerItems(array_merge([(string)($it['exclusions']??'')],$data['policy']['exclusions'])));
    $customerContent=tsPSOBuildCustomerContent($data);
    // The supplied Package Master schema stores this summary in VARCHAR(255).
    // Keep the complete supplier narrative in description.
    $shortDescription=tsPSOTrimCustomerText((string)$customerContent['short_description'],255);
    $fullDescription=trim((string)$customerContent['description']);
    $tourHighlights=trim((string)$customerContent['tour_highlights']);
    $terms=tsPSOBuildPackageTerms($data);
    $legacyIt=[];foreach($data['days'] as $d){$legacyIt[]='Day '.(int)$d['day_number'].': '.tsPSOCustomerText((string)$d['day_title'])."\n".tsPSOCustomerText((string)$d['day_description']);}
    $packageType='holiday';
    try{$q=$pdo->query("SELECT slug FROM package_types WHERE status='active' ORDER BY sort_order,id LIMIT 1");$candidate=trim((string)($q->fetchColumn()?:''));if($candidate!=='')$packageType=$candidate;}catch(Throwable $ignored){}
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $sql="INSERT INTO packages(destination_id,title,slug,package_code,short_description,description,duration_days,duration_nights,child_age_min,child_age_max,child_price,regular_price,sale_price,currency,package_type,source_type,source_supplier_id,source_contract_version_id,source_itinerary_id,source_supplier_package_code,main_image,inclusions,exclusions,itinerary,terms,tour_highlights,featured,bestseller,status,views)
              VALUES(?,?,?,?,?,?,?, ?,0,0,NULL,NULL,0,?,?,?,?,?,?,?,NULL,?,?,?,?,?,0,0,'draft',0)";
        $pdo->prepare($sql)->execute([
            (int)$it['destination_id']>0?(int)$it['destination_id']:null,$title,$slug,$code,
            $shortDescription!==''?$shortDescription:null,$fullDescription!==''?$fullDescription:null,
            (int)$it['duration_days'],(int)$it['duration_nights'],$currency,$packageType,
            'supplier_imported',(int)$it['supplier_id'],(int)$it['contract_version_id'],(int)$it['id'],trim((string)$it['supplier_package_code'])?:null,
            $inclusions!==''?$inclusions:null,$exclusions!==''?$exclusions:null,$legacyIt?implode("\n\n",$legacyIt):((string)($it['itinerary_text']??'')?:null),$terms!==''?$terms:null,$tourHighlights!==''?$tourHighlights:null
        ]);
        $packageId=(int)$pdo->lastInsertId();
        if($packageId<=0)throw new RuntimeException('Unable to create TRAVSCOPE package.');
        $autoCover=tsPSODestinationCoverImage($pdo,(int)($it['destination_id']??0));
        if($autoCover!=='')$pdo->prepare("UPDATE packages SET main_image=? WHERE id=? AND (main_image IS NULL OR TRIM(main_image)='')")->execute([$autoCover,$packageId]);
        if($data['days']){
            $ins=$pdo->prepare("INSERT INTO package_itinerary_days(package_id,day_number,title,description,image,sort_order) VALUES(?,?,?,?,NULL,?)");
            foreach($data['days'] as $d){$n=max(1,(int)$d['day_number']);$dayTitle=tsPSOCustomerText((string)$d['day_title']);$dayDescription=tsPSOCustomerText((string)$d['day_description']);$ins->execute([$packageId,$n,$dayTitle!==''?$dayTitle:null,$dayDescription!==''?$dayDescription:null,$n]);}
        }
        if($sourceActive)tsPSOInsertPackageMasterReferencePrices($pdo,$packageId,(int)$it['contract_id']);
        $optionId=tsPSOLink($pdo,$packageId,$itineraryId,'primary_dmc',true,$adminId,'Imported from supplier Rate Book.');
        if(!$sourceActive)$pdo->prepare('UPDATE package_supplier_options SET enabled_for_quotation=0 WHERE id=? AND package_id=?')->execute([$optionId,$packageId]);
        if($ownsTransaction)$pdo->commit();
        return $packageId;
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/**
 * V24.12 - Rebuild customer-facing content for an existing supplier-imported
 * Package Master record from its linked supplier package. This intentionally
 * preserves package code, destination, prices and itinerary images.
 */
function tsPSOSyncPackageContentFromSupplier(PDO $pdo, int $packageId, int $adminId=0): array
{
    $package=tsPSOPackage($pdo,$packageId);
    if(!$package)throw new RuntimeException('Package not found.');
    $itineraryId=(int)($package['source_itinerary_id']??0);
    if($itineraryId<=0 && tsPSOTableExists($pdo,'package_supplier_options')){
        $q=$pdo->prepare("SELECT itinerary_id FROM package_supplier_options WHERE package_id=? AND status='active' ORDER BY is_primary DESC,id LIMIT 1");
        $q->execute([$packageId]);$itineraryId=(int)($q->fetchColumn()?:0);
    }
    if($itineraryId<=0)throw new RuntimeException('No linked supplier itinerary was found for this package.');

    $data=tsPSOImportPayload($pdo,$itineraryId);$it=$data['itinerary'];
    $content=tsPSOBuildCustomerContent($data);
    $inclusions=implode("\n",tsPSOUniqueCustomerItems(array_merge([(string)($it['inclusions']??'')],$data['policy']['inclusions'])));
    $exclusions=implode("\n",tsPSOUniqueCustomerItems(array_merge([(string)($it['exclusions']??'')],$data['policy']['exclusions'])));
    $terms=tsPSOBuildPackageTerms($data);
    $legacy=[];foreach($data['days'] as $d){$legacy[]='Day '.(int)$d['day_number'].': '.tsPSOCustomerText((string)$d['day_title'])."\n".tsPSOCustomerText((string)$d['day_description']);}

    $pdo->beginTransaction();
    try{
        $pdo->prepare("UPDATE packages SET short_description=?,description=?,duration_days=?,duration_nights=?,inclusions=?,exclusions=?,itinerary=?,terms=?,tour_highlights=? WHERE id=?")
            ->execute([
                tsPSOTrimCustomerText((string)$content['short_description'],255)?:null,
                trim((string)$content['description'])?:null,
                (int)($it['duration_days']??$package['duration_days']??0),
                (int)($it['duration_nights']??$package['duration_nights']??0),
                $inclusions!==''?$inclusions:null,
                $exclusions!==''?$exclusions:null,
                $legacy?implode("\n\n",$legacy):null,
                $terms!==''?$terms:null,
                trim((string)$content['tour_highlights'])?:null,
                $packageId
            ]);

        $coverAdded=false;
        if(trim((string)($package['main_image']??''))===''){
            $autoCover=tsPSODestinationCoverImage($pdo,(int)($it['destination_id']??$package['destination_id']??0));
            if($autoCover!==''){
                $pdo->prepare("UPDATE packages SET main_image=? WHERE id=? AND (main_image IS NULL OR TRIM(main_image)='')")->execute([$autoCover,$packageId]);
                $coverAdded=true;
            }
        }
        $priceRowsAdded=0;
        if(tsPSOTableExists($pdo,'package_date_prices')){
            $pc=$pdo->prepare('SELECT COUNT(*) FROM package_date_prices WHERE package_id=? AND (COALESCE(price_3_star,0)>0 OR COALESCE(price_4_star,0)>0 OR COALESCE(price_5_star,0)>0)');
            $pc->execute([$packageId]);
            if((int)$pc->fetchColumn()===0)$priceRowsAdded=tsPSOInsertPackageMasterReferencePrices($pdo,$packageId,(int)($it['contract_id']??0));
        }

        $existing=[];
        $q=$pdo->prepare("SELECT id,day_number,image FROM package_itinerary_days WHERE package_id=? ORDER BY id");
        $q->execute([$packageId]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$n=(int)$row['day_number'];if(!isset($existing[$n]))$existing[$n]=$row;}
        $keep=[];
        $ins=$pdo->prepare("INSERT INTO package_itinerary_days(package_id,day_number,title,description,image,sort_order) VALUES(?,?,?,?,NULL,?)");
        $upd=$pdo->prepare("UPDATE package_itinerary_days SET title=?,description=?,sort_order=? WHERE id=? AND package_id=?");
        foreach($data['days'] as $d){
            $n=max(1,(int)$d['day_number']);$keep[]=$n;
            $title=tsPSOCustomerText((string)$d['day_title']);$desc=tsPSOCustomerText((string)$d['day_description']);
            if(isset($existing[$n]))$upd->execute([$title!==''?$title:null,$desc!==''?$desc:null,$n,(int)$existing[$n]['id'],$packageId]);
            else $ins->execute([$packageId,$n,$title!==''?$title:null,$desc!==''?$desc:null,$n]);
        }
        if($keep){$ph=implode(',',array_fill(0,count($keep),'?'));$args=array_merge([$packageId],$keep);$pdo->prepare("DELETE FROM package_itinerary_days WHERE package_id=? AND day_number NOT IN ($ph)")->execute($args);}
        $pdo->commit();
        return ['package_id'=>$packageId,'days_synced'=>count($data['days']),'content_synced'=>1,'price_rows_added'=>$priceRowsAdded,'cover_added'=>$coverAdded];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function tsPSOSupplierPackageCandidates(PDO $pdo, int $packageId=0, int $supplierId=0): array
{
    $package=$packageId>0?tsPSOPackage($pdo,$packageId):null;
    $sql="SELECT i.id itinerary_id,i.contract_id,i.supplier_package_code,i.source_heading,i.title,i.duration_nights,i.duration_days,i.destination_id,i.meal_plan,i.status itinerary_status,
                 s.id supplier_id,s.company_name,s.supplier_code,s.priority supplier_priority,
                 v.id contract_version_id,v.season_name,v.season_type,v.rate_priority,v.valid_from,v.valid_to,v.status version_status,
                 c.contract_code,c.contract_name,c.status contract_status,c.record_type,c.entry_mode,d.name destination_name,
                 (SELECT COUNT(*) FROM supplier_contract_versions vx WHERE vx.contract_id=c.id AND vx.status='active') active_seasons,
                 (SELECT COUNT(*) FROM supplier_contract_rate_groups g WHERE g.contract_id=c.id AND g.status='active') active_rate_groups,
                 (SELECT COUNT(*) FROM supplier_contract_pax_rates pr JOIN supplier_contract_rate_groups g2 ON g2.id=pr.rate_group_id WHERE g2.contract_id=c.id AND pr.status='active') active_pax_rows
          FROM supplier_contract_itineraries i
          JOIN suppliers s ON s.id=i.supplier_id
          JOIN supplier_contract_versions v ON v.id=i.contract_version_id
          JOIN supplier_contracts c ON c.id=i.contract_id
          LEFT JOIN destinations d ON d.id=i.destination_id
          WHERE s.status='active' AND i.status='active' AND v.status='active' AND c.status='active'
            AND c.record_type='package'";
    $params=[];
    if($supplierId>0){$sql.=' AND s.id=?';$params[]=$supplierId;}
    if($package && (int)$package['destination_id']>0){$sql.=' AND (i.destination_id=? OR i.destination_id IS NULL)';$params[]=(int)$package['destination_id'];}
    $sql.=" ORDER BY CASE s.priority WHEN 'preferred' THEN 1 WHEN 'regular' THEN 2 ELSE 3 END,ABS(i.duration_days-".(int)($package['duration_days']??0)."),s.company_name,i.title,v.rate_priority DESC,v.valid_from DESC,v.version_no DESC";
    $st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    $dedup=[];foreach($rows as $r){$k=(int)$r['contract_id'];if(!isset($dedup[$k]))$dedup[$k]=$r;}
    return array_values($dedup);
}

function tsPSOGroupLink(PDO $pdo, int $packageId, array $group): ?array
{
    if($packageId<=0 || !tsPSOTableExists($pdo,'package_supplier_options'))return null;
    $hasContractId=tsPSOColumnExists($pdo,'package_supplier_options','contract_id');
    if($hasContractId){
        $st=$pdo->prepare("SELECT o.id,o.relationship_type,o.is_primary,o.enabled_for_quotation,o.status
                      FROM package_supplier_options o
                      WHERE o.package_id=? AND o.supplier_id=? AND o.status='active' AND o.enabled_for_quotation=1
                        AND ((o.contract_id IS NOT NULL AND o.contract_id=?) OR o.itinerary_id=? OR (o.itinerary_id IS NULL AND o.contract_version_id=?))
                      ORDER BY o.is_primary DESC,o.id LIMIT 1");
        $st->execute([$packageId,(int)($group['supplier_id']??0),(int)($group['contract_id']??0),(int)($group['itinerary_id']??0),(int)($group['contract_version_id']??0)]);
    }else{
        $st=$pdo->prepare("SELECT o.id,o.relationship_type,o.is_primary,o.enabled_for_quotation,o.status FROM package_supplier_options o WHERE o.package_id=? AND o.supplier_id=? AND o.status='active' AND o.enabled_for_quotation=1 AND (o.itinerary_id=? OR (o.itinerary_id IS NULL AND o.contract_version_id=?)) ORDER BY o.is_primary DESC,o.id LIMIT 1");
        $st->execute([$packageId,(int)($group['supplier_id']??0),(int)($group['itinerary_id']??0),(int)($group['contract_version_id']??0)]);
    }
    return $st->fetch(PDO::FETCH_ASSOC)?:null;
}
