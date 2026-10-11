<?php
declare(strict_types=1);

/** Parse the entire selection before touching records; never coerce bad IDs. */
function tsPackageDeleteIds($selection): array
{
    if (!is_array($selection) || !$selection || count($selection) > 500) {
        throw new RuntimeException('Select between 1 and 500 packages to delete.');
    }
    $ids = [];
    foreach ($selection as $value) {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]*$/D', (string)$value)
            || strlen((string)$value) > strlen((string)PHP_INT_MAX)
            || (strlen((string)$value) === strlen((string)PHP_INT_MAX) && strcmp((string)$value, (string)PHP_INT_MAX) > 0)) {
            throw new RuntimeException('Invalid package selection. Refresh and select the packages again.');
        }
        $ids[(int)$value] = (int)$value;
    }
    sort($ids, SORT_NUMERIC);
    return array_values($ids);
}

/** Metadata errors must never bypass linked-history checks. */
function tsPackageDeleteColumns(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT table_name,column_name FROM information_schema.columns WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_NUM);
        if (!$rows) throw new RuntimeException('Package schema unavailable.');
        $columns = [];
        foreach ($rows as $row) {
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $row[0]) || !preg_match('/^[A-Za-z0-9_]+$/D', $row[1])) throw new RuntimeException('Unsupported package schema.');
            $columns[$row[0]][$row[1]] = true;
        }
        return $columns;
    } catch (Throwable $error) {
        $columns = [];
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $row) {
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $row[0])) throw new RuntimeException('Unsupported package schema.');
            foreach ($pdo->query('SHOW COLUMNS FROM `'.$row[0].'`')->fetchAll(PDO::FETCH_ASSOC) as $column) $columns[$row[0]][$column['Field']] = true;
        }
        if (!$columns) throw new RuntimeException('Unable to verify linked package records.');
        return $columns;
    }
}

/** Atomic batch deletion, including children without package foreign keys. */
function tsPackageDeleteSelected(PDO $pdo, array $selection, array $scope = []): array
{
    $ids = tsPackageDeleteIds($selection);
    if ($pdo->inTransaction()) throw new RuntimeException('Another package update is in progress.');
    $owned = ['wishlists','package_images','package_itinerary_days','package_date_prices','package_child_prices',
              'package_hotels','package_occupancy_prices','package_services','package_supplier_options'];
    $columns = tsPackageDeleteColumns($pdo);
    if (!isset($columns['packages']['id'], $columns['bookings']['package_id'])) throw new RuntimeException('Unable to verify package booking records.');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $where = ['p.id IN ('.$placeholders.')'];
    $search = trim((string)($scope['q'] ?? ''));
    if ($search !== '') {
        $where[] = '(p.title LIKE ? OR p.package_code LIKE ? OR p.slug LIKE ? OR d.name LIKE ? OR d.country LIKE ?)';
        array_push($params, ...array_fill(0, 5, '%'.$search.'%'));
    }
    $status = strtolower(trim((string)($scope['status'] ?? '')));
    if (in_array($status, ['active','inactive','draft'], true)) { $where[]='p.status=?'; $params[]=$status; }
    $type = strtolower(trim((string)($scope['type'] ?? '')));
    if ($type !== '') { $where[]='p.package_type=?'; $params[]=$type; }
    if ((int)($scope['destination'] ?? 0) > 0) { $where[]='p.destination_id=?'; $params[]=(int)$scope['destination']; }
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT p.id,p.title,p.main_image FROM packages p LEFT JOIN destinations d ON d.id=p.destination_id WHERE '.implode(' AND ', $where).' ORDER BY p.id FOR UPDATE');
        $statement->execute($params);
        $packages = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($packages) !== count($ids)) throw new RuntimeException('A selected package is missing or no longer matches this list. Refresh and select again. No packages were deleted.');
        foreach ($columns as $table => $fields) {
            if (!isset($fields['package_id']) || in_array($table, $owned, true)) continue;
            $check = $pdo->prepare('SELECT package_id FROM `'.$table.'` WHERE package_id IN ('.$placeholders.') LIMIT 1 FOR UPDATE');
            $check->execute($ids);
            if ($check->fetchColumn() !== false) throw new RuntimeException('A selected package has linked booking, quotation, enquiry or other history. Set it to inactive instead. No packages were deleted.');
        }
        if (isset($columns['trip_service_requirements']['source_package_service_id'], $columns['package_services']['package_id'])) {
            $check = $pdo->prepare('SELECT r.id FROM trip_service_requirements r JOIN package_services s ON s.id=r.source_package_service_id WHERE s.package_id IN ('.$placeholders.') LIMIT 1 FOR UPDATE');
            $check->execute($ids);
            if ($check->fetchColumn() !== false) throw new RuntimeException('A selected package is used in trip service history. No packages were deleted.');
        }
        if (isset($columns['package_services']['source_package_hotel_id'], $columns['package_hotels']['package_id'])) {
            $check = $pdo->prepare('SELECT s.id FROM package_services s JOIN package_hotels h ON h.id=s.source_package_hotel_id WHERE h.package_id IN ('.$placeholders.') AND s.package_id NOT IN ('.$placeholders.') LIMIT 1 FOR UPDATE');
            $check->execute(array_merge($ids, $ids));
            if ($check->fetchColumn() !== false) throw new RuntimeException('A selected hotel assignment is used by another package. No packages were deleted.');
        }
        $images = array_column($packages, 'main_image');
        foreach (['package_images','package_itinerary_days'] as $table) {
            if (!isset($columns[$table]['image'], $columns[$table]['package_id'])) continue;
            $statement = $pdo->prepare('SELECT image FROM `'.$table.'` WHERE package_id IN ('.$placeholders.') FOR UPDATE');
            $statement->execute($ids);
            $images = array_merge($images, $statement->fetchAll(PDO::FETCH_COLUMN));
        }
        // Services refer to hotel assignments on installations without FKs.
        foreach (['package_supplier_options','package_services','package_hotels','package_occupancy_prices','package_child_prices',
                  'package_date_prices','package_itinerary_days','package_images','wishlists'] as $table) {
            if (!isset($columns[$table]['package_id'])) continue;
            $pdo->prepare('DELETE FROM `'.$table.'` WHERE package_id IN ('.$placeholders.')')->execute($ids);
        }
        $statement = $pdo->prepare('DELETE FROM packages WHERE id IN ('.$placeholders.')');
        $statement->execute($ids);
        if ($statement->rowCount() !== count($ids)) throw new RuntimeException('Package selection changed. No packages were deleted.');
        $pdo->commit();
        return ['deleted'=>count($ids), 'images'=>array_values(array_unique(array_filter($images, 'is_string')))];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
