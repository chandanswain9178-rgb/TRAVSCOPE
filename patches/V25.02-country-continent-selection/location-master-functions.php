<?php
declare(strict_types=1);

/**
 * TRAVSCOPE V9 - Destination Child City / Location Master
 *
 * Architecture:
 *   Destination (Tamil Nadu / Kashmir / Andaman)
 *      -> Child city/location (Chennai / Madurai / Ooty ...)
 *         -> Supplier hotel / vehicle / activity / rate
 *
 * Safe for shared MariaDB hosting: no hard foreign keys.
 */

if (!function_exists('tsLocationTableExists')) {
    function tsLocationTableExists(PDO $pdo, string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
            $st->execute([$table]);
            if ((int)$st->fetchColumn() > 0) return true;
        } catch (Throwable $ignored) {}
        try {
            $pdo->query('SELECT 1 FROM `'.$table.'` LIMIT 0');
            return true;
        } catch (Throwable $ignored) {}
        return false;
    }
}

if (!function_exists('tsLocationColumnExists')) {
    function tsLocationColumnExists(PDO $pdo, string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }
        try {
            $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
            $st->execute([$table,$column]);
            if ((int)$st->fetchColumn()>0) {
                return true;
            }
        } catch (Throwable $ignored) {
            // Some shared cPanel/MySQL users cannot query information_schema.
        }
        try {
            $pdo->query("SELECT `{$column}` FROM `{$table}` LIMIT 0");
            return true;
        } catch (Throwable $ignored) {
            return false;
        }
    }
}

if (!function_exists('tsLocationNormalize')) {
    function tsLocationNormalize(string $value): string
    {
        $value = trim((string)(preg_replace('/\s+/u',' ', $value) ?? $value));
        return $value;
    }
}

if (!function_exists('tsLocationEnsureSchema')) {
    function tsLocationEnsureSchema(PDO $pdo): array
    {
        $result=['ready'=>false,'changed'=>false,'message'=>''];
        if (!tsLocationTableExists($pdo,'destinations')) {
            $result['message']='Destination Master table is missing.';
            return $result;
        }

        if (!tsLocationTableExists($pdo,'destination_locations')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS destination_locations (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                destination_id BIGINT UNSIGNED NOT NULL,
                location_name VARCHAR(150) NOT NULL,
                location_type VARCHAR(30) NOT NULL DEFAULT 'city',
                source VARCHAR(30) NOT NULL DEFAULT 'manual',
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                sort_order INT NOT NULL DEFAULT 0,
                created_by_admin_id BIGINT UNSIGNED DEFAULT NULL,
                updated_by_admin_id BIGINT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                UNIQUE KEY uq_destination_location_name(destination_id,location_name),
                KEY idx_destination_location(destination_id,status,location_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $result['changed']=true;
        }

        // Structured city relation for Supplier 360 rates.
        if (tsLocationTableExists($pdo,'supplier_rate_cards') && !tsLocationColumnExists($pdo,'supplier_rate_cards','location_id')) {
            try {
                $pdo->exec("ALTER TABLE supplier_rate_cards ADD COLUMN location_id BIGINT UNSIGNED DEFAULT NULL AFTER destination_id");
                $result['changed']=true;
            } catch (Throwable $ignored) {}
        }
        if (tsLocationTableExists($pdo,'supplier_rate_cards')) {
            try {
                $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='supplier_rate_cards' AND index_name='idx_rate_card_location_id'");
                $st->execute();
                if ((int)$st->fetchColumn()===0) {
                    $pdo->exec("ALTER TABLE supplier_rate_cards ADD KEY idx_rate_card_location_id (supplier_id,destination_id,location_id,service_type,status)");
                    $result['changed']=true;
                }
            } catch (Throwable $ignored) {}
        }

        // Hotel Master keeps the parent destination and a child location separately.
        if (tsLocationTableExists($pdo,'hotels') && !tsLocationColumnExists($pdo,'hotels','location_id')) {
            try {
                $pdo->exec("ALTER TABLE hotels ADD COLUMN location_id BIGINT UNSIGNED DEFAULT NULL AFTER destination_id");
                $result['changed']=true;
            } catch (Throwable $ignored) {}
        }
        if (tsLocationTableExists($pdo,'hotels')) {
            try {
                $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='hotels' AND index_name='idx_hotel_location'");
                $st->execute();
                if ((int)$st->fetchColumn()===0) {
                    $pdo->exec("ALTER TABLE hotels ADD KEY idx_hotel_location (destination_id,location_id,star_category,status,is_preferred)");
                    $result['changed']=true;
                }
            } catch (Throwable $ignored) {}
            // V6/V7 used destination + hotel + star as globally unique. V9 must allow
            // the same hotel name in two different cities under one destination/state.
            try {
                $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='hotels' AND index_name='uq_hotel_destination_name_star'");
                $st->execute();
                if ((int)$st->fetchColumn()>0) {
                    $pdo->exec("ALTER TABLE hotels DROP INDEX uq_hotel_destination_name_star");
                    $result['changed']=true;
                }
            } catch (Throwable $ignored) {}
            try {
                $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='hotels' AND index_name='uq_hotel_destination_location_name_star'");
                $st->execute();
                if ((int)$st->fetchColumn()===0 && tsLocationColumnExists($pdo,'hotels','location_id')) {
                    $pdo->exec("ALTER TABLE hotels ADD UNIQUE KEY uq_hotel_destination_location_name_star (destination_id,location_id,hotel_name,star_category)");
                    $result['changed']=true;
                }
            } catch (Throwable $ignored) {}
        }

        foreach (['supplier_contract_hotel_inventory','supplier_contract_vehicle_rules','supplier_hotel_options','supplier_vehicle_options'] as $table) {
            if (tsLocationTableExists($pdo,$table) && !tsLocationColumnExists($pdo,$table,'location_id')) {
                try {
                    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN location_id BIGINT UNSIGNED DEFAULT NULL AFTER destination_id");
                    $result['changed']=true;
                } catch (Throwable $ignored) {}
            }
        }

        // Seed the location master from all location-aware data already stored by TRAVSCOPE.
        $seedQueries=[];
        if (tsLocationTableExists($pdo,'supplier_rate_cards') && tsLocationColumnExists($pdo,'supplier_rate_cards','location_city')) {
            $seedQueries[]="SELECT destination_id,TRIM(location_city) city FROM supplier_rate_cards WHERE destination_id IS NOT NULL AND TRIM(COALESCE(location_city,''))<>''";
        }
        if (tsLocationTableExists($pdo,'supplier_contract_hotel_inventory')) {
            $seedQueries[]="SELECT destination_id,TRIM(city) city FROM supplier_contract_hotel_inventory WHERE destination_id IS NOT NULL AND TRIM(COALESCE(city,''))<>''";
        }
        if (tsLocationTableExists($pdo,'supplier_hotel_options')) {
            $seedQueries[]="SELECT destination_id,TRIM(city) city FROM supplier_hotel_options WHERE destination_id IS NOT NULL AND TRIM(COALESCE(city,''))<>''";
        }
        if (tsLocationTableExists($pdo,'supplier_vehicle_options')) {
            $seedQueries[]="SELECT destination_id,TRIM(city) city FROM supplier_vehicle_options WHERE destination_id IS NOT NULL AND TRIM(COALESCE(city,''))<>''";
        }
        if (tsLocationTableExists($pdo,'supplier_contract_vehicle_rules') && tsLocationColumnExists($pdo,'supplier_contract_vehicle_rules','city')) {
            $seedQueries[]="SELECT destination_id,TRIM(city) city FROM supplier_contract_vehicle_rules WHERE destination_id IS NOT NULL AND TRIM(COALESCE(city,''))<>''";
        }
        if (tsLocationTableExists($pdo,'hotels')) {
            $seedQueries[]="SELECT destination_id,TRIM(city) city FROM hotels WHERE destination_id IS NOT NULL AND TRIM(COALESCE(city,''))<>''";
        }
        if ($seedQueries) {
            // Discovery must not override an administrator's saved status or timestamps.
            $ins=$pdo->prepare("INSERT INTO destination_locations(destination_id,location_name,location_type,source,status) VALUES (?,?,'city','existing_data','active') ON DUPLICATE KEY UPDATE id=id");
            foreach ($seedQueries as $sql) {
                try {
                    foreach (($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                        $did=(int)($row['destination_id']??0);$city=tsLocationNormalize((string)($row['city']??''));
                        if($did>0 && $city!=='') $ins->execute([$did,$city]);
                    }
                } catch (Throwable $ignored) {}
            }
        }

        // Backfill relation IDs from exact destination + city matches.
        if (tsLocationTableExists($pdo,'supplier_rate_cards') && tsLocationColumnExists($pdo,'supplier_rate_cards','location_id')) {
            try {$pdo->exec("UPDATE supplier_rate_cards rc JOIN destination_locations dl ON dl.destination_id=rc.destination_id AND LOWER(TRIM(dl.location_name))=LOWER(TRIM(rc.location_city)) SET rc.location_id=dl.id WHERE rc.location_id IS NULL AND TRIM(COALESCE(rc.location_city,''))<>''");} catch(Throwable $ignored){}
        }
        if (tsLocationTableExists($pdo,'hotels') && tsLocationColumnExists($pdo,'hotels','location_id')) {
            try {$pdo->exec("UPDATE hotels h JOIN destination_locations dl ON dl.destination_id=h.destination_id AND LOWER(TRIM(dl.location_name))=LOWER(TRIM(h.city)) SET h.location_id=dl.id WHERE h.location_id IS NULL AND TRIM(COALESCE(h.city,''))<>''");} catch(Throwable $ignored){}
        }
        foreach (['supplier_contract_hotel_inventory','supplier_contract_vehicle_rules','supplier_hotel_options','supplier_vehicle_options'] as $table) {
            if (tsLocationTableExists($pdo,$table) && tsLocationColumnExists($pdo,$table,'location_id') && tsLocationColumnExists($pdo,$table,'city')) {
                try {$pdo->exec("UPDATE `{$table}` t JOIN destination_locations dl ON dl.destination_id=t.destination_id AND LOWER(TRIM(dl.location_name))=LOWER(TRIM(t.city)) SET t.location_id=dl.id WHERE t.location_id IS NULL AND TRIM(COALESCE(t.city,''))<>''");} catch(Throwable $ignored){}
            }
        }

        $result['ready']=tsLocationTableExists($pdo,'destination_locations');
        return $result;
    }
}

if (!function_exists('tsLocationUpsert')) {
    function tsLocationUpsert(PDO $pdo, int $destinationId, string $name, string $source='manual', ?int $adminId=null): int
    {
        if ($destinationId<=0) return 0;
        $name=tsLocationNormalize($name);
        if ($name==='') return 0;
        if (mb_strlen($name,'UTF-8')>150) throw new RuntimeException('City / location name must be 150 characters or fewer.');
        tsLocationEnsureSchema($pdo);
        if (!tsLocationTableExists($pdo,'destination_locations')) return 0;
        $st=$pdo->prepare("SELECT id FROM destination_locations WHERE destination_id=? AND LOWER(TRIM(location_name))=LOWER(TRIM(?)) LIMIT 1");
        $st->execute([$destinationId,$name]);
        $id=(int)($st->fetchColumn()?:0);
        if ($id>0) {
            // Only an explicit administrator status request may reactivate a row.
            return $id;
        }
        $ins=$pdo->prepare("INSERT INTO destination_locations(destination_id,location_name,location_type,source,status,created_by_admin_id,updated_by_admin_id) VALUES (?,?,'city',?,'active',?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
        $ins->execute([$destinationId,$name,$source,$adminId?:null,$adminId?:null]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('tsLocationList')) {
    function tsLocationList(PDO $pdo, int $destinationId): array
    {
        if ($destinationId<=0 || !tsLocationTableExists($pdo,'destination_locations')) return [];
        $st=$pdo->prepare("SELECT id,destination_id,location_name,location_type,source,status FROM destination_locations WHERE destination_id=? AND status='active' ORDER BY sort_order,location_name");
        $st->execute([$destinationId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('tsLocationAdminList')) {
    /** Administrative listing; operational consumers continue using tsLocationList. */
    function tsLocationAdminList(PDO $pdo, int $destinationId): array
    {
        if ($destinationId<=0 || !tsLocationTableExists($pdo,'destination_locations')) return [];
        $st=$pdo->prepare("SELECT id,destination_id,location_name,location_type,source,status FROM destination_locations WHERE destination_id=? ORDER BY sort_order,location_name");
        $st->execute([$destinationId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('tsLocationSetStatus')) {
    function tsLocationSetStatus(PDO $pdo, int $destinationId, int $locationId, string $status, ?int $adminId=null): bool
    {
        if (!in_array($status,['active','inactive'],true)) throw new RuntimeException('Choose a valid location status.');
        $destination=tsLocationDestination($pdo,$destinationId);
        if (!$destination || ($destination['status']??'')!=='active') throw new RuntimeException('Select an active destination.');
        $st=$pdo->prepare("SELECT status FROM destination_locations WHERE id=? AND destination_id=? LIMIT 1");
        $st->execute([$locationId,$destinationId]);
        $current=$st->fetchColumn();
        if ($current===false) throw new RuntimeException('Location not found in the selected destination.');
        if ($current===$status) return true;
        $pdo->prepare("UPDATE destination_locations SET status=?,updated_by_admin_id=?,updated_at=NOW() WHERE id=? AND destination_id=? AND status<>?")
            ->execute([$status,$adminId?:null,$locationId,$destinationId,$status]);
        return true;
    }
}

if (!function_exists('tsLocationDestination')) {
    function tsLocationDestination(PDO $pdo, int $destinationId): ?array
    {
        if ($destinationId<=0 || !tsLocationTableExists($pdo,'destinations')) return null;
        $st=$pdo->prepare("SELECT id,name,country,status FROM destinations WHERE id=? LIMIT 1");
        $st->execute([$destinationId]);
        $row=$st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('tsLocationGeoStateName')) {
    function tsLocationGeoStateName(string $destinationName): string
    {
        $n=trim($destinationName);
        $key=strtolower(preg_replace('/[^a-z0-9]+/i',' ', $n) ?? $n);
        $aliases=[
            'kashmir'=>'Jammu and Kashmir',
            'jammu kashmir'=>'Jammu and Kashmir',
            'jammu and kashmir'=>'Jammu and Kashmir',
            'andaman'=>'Andaman and Nicobar Islands',
            'andaman nicobar'=>'Andaman and Nicobar Islands',
            'andaman and nicobar'=>'Andaman and Nicobar Islands',
            'pondicherry'=>'Puducherry',
            'orissa'=>'Odisha'
        ];
        return $aliases[$key] ?? $n;
    }
}

if (!function_exists('tsLocationGeoRequest')) {
    /** Country destinations (for example Bhutan) need the country-wide endpoint. */
    function tsLocationGeoRequest(string $country, string $state): array
    {
        $country=tsLocationNormalize($country);$state=tsLocationNormalize($state);
        $countryScope=strcasecmp($country,$state)===0;
        return ['url'=>'https://countriesnow.space/api/v0.1/countries/'.($countryScope?'cities':'state/cities'),
            'payload'=>$countryScope?['country'=>$country]:['country'=>$country,'state'=>$state]];
    }
}

if (!function_exists('tsLocationRemoteResult')) {
    /** Pure response classification also makes transport/error cases testable offline. */
    function tsLocationRemoteResult($raw, int $httpStatus): array
    {
        $result=['cities'=>[],'outcome'=>'failed','source'=>'geo_api','message'=>'Geographic service request failed. Try again or add a location manually.'];
        if (!is_string($raw) || $raw==='' || $httpStatus<200 || $httpStatus>=500 || ($httpStatus>=300 && $httpStatus<400)
            || ($httpStatus>=400 && !in_array($httpStatus,[400,404,422],true))) return $result;
        $json=json_decode($raw,true);
        if (!is_array($json)) { $result['message']='Geographic service returned an invalid response. Add a location manually or try again.';return $result; }
        if ($httpStatus>=400 || !empty($json['error'])) {
            $result['outcome']='unavailable';
            $result['message']='Geographic service has no city list for this destination. Add a city / location manually.';
            return $result;
        }
        if (!is_array($json['data']??null)) { $result['message']='Geographic service returned an invalid city list. Add a location manually or try again.';return $result; }
        $out=[];$seen=[];
        foreach ($json['data'] as $city) {
            if (!is_string($city)) { $result['message']='Geographic service returned an invalid city list. Add a location manually or try again.';return $result; }
            $city=tsLocationNormalize($city);$key=mb_strtolower($city,'UTF-8');
            if ($city!=='' && mb_strlen($city,'UTF-8')<=150 && !isset($seen[$key])) { $seen[$key]=true;$out[]=$city; }
        }
        natcasesort($out);$result['cities']=array_values($out);
        $result['outcome']=$out?'available':'unavailable';
        $result['message']=$out?'Geographic service city list available.':'Geographic service has no city names for this destination. Add a city / location manually.';
        return $result;
    }
}

if (!function_exists('tsLocationRemoteCitiesResult')) {
    function tsLocationRemoteCitiesResult(string $country, string $state): array
    {
        if (trim($country)==='' || trim($state)==='') return ['cities'=>[],'outcome'=>'unavailable','source'=>'geo_api','message'=>'Destination country or name is missing. Add a location manually.'];
        if (!function_exists('curl_init')) return ['cities'=>[],'outcome'=>'unavailable','source'=>'geo_api','message'=>'Geographic service is unavailable on this server. Add a location manually.'];
        $request=tsLocationGeoRequest($country,$state);
        $ch=curl_init($request['url']);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode($request['payload'],JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>true,
            CURLOPT_CONNECTTIMEOUT=>6,
            CURLOPT_TIMEOUT=>15,
            CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json'],
            CURLOPT_USERAGENT=>'TRAVSCOPE Destination Location Master'
        ]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        return tsLocationRemoteResult($raw,$status);
    }
}

if (!function_exists('tsLocationRemoteCities')) {
    function tsLocationRemoteCities(string $country, string $state): array
    {
        return tsLocationRemoteCitiesResult($country,$state)['cities'];
    }
}

if (!function_exists('tsLocationFallbackCities')) {
    function tsLocationFallbackCities(string $country, string $destinationName): array
    {
        /*
         * Offline safety net used when a hosting provider blocks/outages the
         * external geo-city service. It intentionally contains the main travel
         * and commercial cities used by Indian DMC/hotel workflows.
         */
        $countryKey=strtolower(trim($country));
        if ($countryKey!=='' && !in_array($countryKey,['india','in'],true)) {
            return [];
        }

        $key=strtolower(trim($destinationName));
        $sets=[
            'andaman'=>['Port Blair','Havelock Island','Swaraj Dweep','Neil Island','Shaheed Dweep','Baratang','Diglipur','Rangat'],
            'andaman and nicobar islands'=>['Port Blair','Havelock Island','Swaraj Dweep','Neil Island','Shaheed Dweep','Baratang','Diglipur','Rangat'],
            'andhra pradesh'=>['Visakhapatnam','Vijayawada','Tirupati','Araku Valley','Amaravati','Guntur','Kakinada','Rajahmundry','Nellore','Srikalahasti'],
            'arunachal pradesh'=>['Itanagar','Tawang','Dirang','Bomdila','Bhalukpong','Ziro','Pasighat','Roing','Tezu','Aalo'],
            'assam'=>['Guwahati','Kaziranga','Jorhat','Tezpur','Dibrugarh','Sivasagar','Silchar','Majuli','Nagaon','Haflong'],
            'bihar'=>['Patna','Gaya','Bodh Gaya','Rajgir','Nalanda','Muzaffarpur','Bhagalpur','Darbhanga'],
            'chandigarh'=>['Chandigarh'],
            'chhattisgarh'=>['Raipur','Bilaspur','Jagdalpur','Durg','Bhilai','Ambikapur'],
            'delhi'=>['New Delhi','Delhi'],
            'goa'=>['Panaji','Calangute','Baga','Candolim','Anjuna','Vagator','Mapusa','Margao','Colva','Palolem','Old Goa'],
            'gujarat'=>['Ahmedabad','Vadodara','Surat','Rajkot','Dwarka','Somnath','Bhuj','Kutch','Gandhinagar','Junagadh','Kevadia'],
            'haryana'=>['Gurugram','Gurgaon','Faridabad','Kurukshetra','Panipat','Ambala','Panchkula'],
            'himachal pradesh'=>['Shimla','Manali','Dharamshala','McLeod Ganj','Dalhousie','Kasauli','Kasol','Kullu','Spiti','Chamba'],
            'jammu and kashmir'=>['Srinagar','Pahalgam','Gulmarg','Sonamarg','Jammu','Katra','Patnitop','Doodhpathri','Yusmarg'],
            'jammu kashmir'=>['Srinagar','Pahalgam','Gulmarg','Sonamarg','Jammu','Katra','Patnitop','Doodhpathri','Yusmarg'],
            'kashmir'=>['Srinagar','Pahalgam','Gulmarg','Sonamarg','Doodhpathri','Yusmarg'],
            'jharkhand'=>['Ranchi','Jamshedpur','Deoghar','Dhanbad','Netarhat','Hazaribagh'],
            'karnataka'=>['Bengaluru','Bangalore','Mysuru','Mysore','Coorg','Madikeri','Mangaluru','Hampi','Chikmagalur','Udupi','Gokarna'],
            'kerala'=>['Kochi','Cochin','Munnar','Alappuzha','Alleppey','Thekkady','Thiruvananthapuram','Kovalam','Wayanad','Kozhikode','Kumarakom','Varkala','Thrissur'],
            'ladakh'=>['Leh','Nubra Valley','Pangong','Kargil','Tso Moriri'],
            'madhya pradesh'=>['Bhopal','Indore','Ujjain','Gwalior','Jabalpur','Khajuraho','Pachmarhi','Sanchi','Orchha'],
            'maharashtra'=>['Mumbai','Pune','Nashik','Nagpur','Aurangabad','Chhatrapati Sambhajinagar','Lonavala','Mahabaleshwar','Shirdi','Alibaug','Kolhapur'],
            'manipur'=>['Imphal','Ukhrul','Churachandpur','Moirang','Loktak'],
            'meghalaya'=>['Shillong','Cherrapunji','Sohra','Dawki','Mawlynnong','Jowai','Nongpoh','Tura','Baghmara'],
            'mizoram'=>['Aizawl','Lunglei','Champhai','Serchhip'],
            'nagaland'=>['Kohima','Dimapur','Mokokchung','Mon','Wokha'],
            'odisha'=>['Angul','Balangir','Balasore','Bargarh','Baripada','Berhampur','Brahmapur','Bhadrak','Bhawanipatna','Bhubaneswar','Boudh','Cuttack','Daringbadi','Deogarh','Dhenkanal','Gopalpur','Jagatsinghpur','Jajpur','Jharsuguda','Kendrapara','Keonjhar','Khordha','Konark','Koraput','Malkangiri','Nabarangpur','Nayagarh','Nuapada','Paradip','Paralakhemundi','Phulbani','Puri','Rayagada','Rourkela','Sambalpur','Sonepur','Subarnapur','Sundargarh','Chilika'],
            'orissa'=>['Angul','Balangir','Balasore','Bargarh','Baripada','Berhampur','Brahmapur','Bhadrak','Bhawanipatna','Bhubaneswar','Boudh','Cuttack','Daringbadi','Deogarh','Dhenkanal','Gopalpur','Jagatsinghpur','Jajpur','Jharsuguda','Kendrapara','Keonjhar','Khordha','Konark','Koraput','Malkangiri','Nabarangpur','Nayagarh','Nuapada','Paradip','Paralakhemundi','Phulbani','Puri','Rayagada','Rourkela','Sambalpur','Sonepur','Subarnapur','Sundargarh','Chilika'],
            'puducherry'=>['Puducherry','Pondicherry','Karaikal','Mahe','Yanam'],
            'punjab'=>['Amritsar','Ludhiana','Jalandhar','Patiala','Bathinda','Pathankot','Anandpur Sahib'],
            'rajasthan'=>['Jaipur','Udaipur','Jodhpur','Jaisalmer','Pushkar','Ajmer','Bikaner','Mount Abu','Ranthambore','Chittorgarh','Kota','Bharatpur'],
            'sikkim'=>['Gangtok','Pelling','Lachung','Lachen','Namchi','Ravangla','Yuksom'],
            'tamil nadu'=>['Chennai','Coimbatore','Coonoor','Dharmapuri','Dindigul','Erode','Hosur','Kanchipuram','Kanniyakumari','Kanyakumari','Karaikudi','Kodaikanal','Kumbakonam','Madurai','Mahabalipuram','Mettupalayam','Nagapattinam','Nagercoil','Ooty','Pollachi','Rameswaram','Salem','Thanjavur','Theni','Thoothukudi','Tiruchirappalli','Tirunelveli','Tiruppur','Tiruvannamalai','Vellore','Yercaud'],
            'telangana'=>['Hyderabad','Warangal','Nizamabad','Karimnagar','Khammam'],
            'tripura'=>['Agartala','Udaipur','Unakoti','Dharmanagar'],
            'uttar pradesh'=>['Lucknow','Agra','Varanasi','Prayagraj','Allahabad','Ayodhya','Mathura','Vrindavan','Noida','Kanpur','Jhansi'],
            'uttarakhand'=>['Dehradun','Mussoorie','Nainital','Rishikesh','Haridwar','Jim Corbett','Corbett','Auli','Joshimath','Kedarnath','Badrinath'],
            'west bengal'=>['Kolkata','Darjeeling','Kalimpong','Digha','Siliguri','Dooars','Mandarmani','Shantiniketan','Sundarbans']
        ];

        $cities=$sets[$key] ?? [];
        $seen=[];$out=[];
        foreach($cities as $city){
            $city=tsLocationNormalize((string)$city);
            $k=strtolower($city);
            if($city!==''&&!isset($seen[$k])){$seen[$k]=1;$out[]=$city;}
        }
        natcasesort($out);
        return array_values($out);
    }
}

if (!function_exists('tsLocationSyncGeoCities')) {
    function tsLocationSyncGeoCities(PDO $pdo, int $destinationId, ?int $adminId=null, bool $force=false): array
    {
        try {
        $unavailable=['cities'=>[],'synced'=>0,'source'=>'unavailable','outcome'=>'unavailable'];
        $schema=tsLocationEnsureSchema($pdo);
        if(!$schema['ready'])return $unavailable+['message'=>$schema['message']??'Location master unavailable.'];
        $destination=tsLocationDestination($pdo,$destinationId);
        if(!$destination || ($destination['status']??'')!=='active')return $unavailable+['message'=>'Select an active destination.'];

        $existing=tsLocationList($pdo,$destinationId);
        if(!$force && count($existing)>=3){
            return['cities'=>$existing,'synced'=>0,'source'=>'local','outcome'=>'up_to_date','message'=>'Loaded saved destination cities.'];
        }

        $country=trim((string)($destination['country']??''));
        if($country==='')return ['cities'=>$existing,'synced'=>0,'source'=>'unavailable','outcome'=>'unavailable','message'=>'Destination country is missing. Add a location manually.'];
        $state=tsLocationGeoStateName((string)$destination['name']);
        $remoteResult=tsLocationRemoteCitiesResult($country,$state);
        $remote=$remoteResult['cities']??[];
        $source='geo_api';
        if(!$remote){$remote=tsLocationFallbackCities($country,(string)$destination['name']);$source='offline_seed';}
        if(!$remote)return ['cities'=>$existing,'synced'=>0,'source'=>(string)($remoteResult['source']??'geo_api'),'outcome'=>(string)($remoteResult['outcome']??'unavailable'),'message'=>(string)($remoteResult['message']??'No city list available. Add a location manually.')];
        $synced=0;
        foreach($remote as $city){
            // For state/destination masters, never create the destination name itself as a child city.
            if(strcasecmp(tsLocationNormalize($city),tsLocationNormalize((string)$destination['name']))===0)continue;
            $before=0;
            try{$st=$pdo->prepare("SELECT id FROM destination_locations WHERE destination_id=? AND LOWER(TRIM(location_name))=LOWER(TRIM(?)) LIMIT 1");$st->execute([$destinationId,$city]);$before=(int)($st->fetchColumn()?:0);}catch(Throwable $ignored){}
            $id=tsLocationUpsert($pdo,$destinationId,$city,$source,$adminId);
            if($id>0 && $before===0)$synced++;
        }
        $cities=tsLocationList($pdo,$destinationId);
        // When real child locations exist, suppress a legacy row that merely repeats the parent destination name.
        if(count($cities)>1){
            $parent=tsLocationNormalize((string)$destination['name']);
            $cities=array_values(array_filter($cities,static fn(array $r):bool=>strcasecmp(tsLocationNormalize((string)$r['location_name']),$parent)!==0));
        }
        return['cities'=>$cities,'synced'=>$synced,'source'=>$source,'outcome'=>$synced>0?'added':'up_to_date',
            'message'=>$synced>0?$synced.' new location(s) added.':'All available locations are already saved. Inactive locations kept their status.'];
        } catch (Throwable $e) {
            $saved=(int)($synced??0);$cities=[];
            try{$cities=tsLocationList($pdo,$destinationId);}catch(Throwable $ignored){}
            return ['cities'=>$cities,'synced'=>$saved,'source'=>(string)($source??'unavailable'),'outcome'=>'failed',
                'message'=>'City sync could not be completed. '.($saved>0?$saved.' new location(s) were saved before the failure. ':'').'Try again or add a location manually.'];
        }
    }
}
