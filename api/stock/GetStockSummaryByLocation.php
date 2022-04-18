<?php
require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

SessionUtils::sessionStart();

if (!SessionUtils::isAuthenticated()) {
    // print error
    $errorMessage = 'You are not authenticated!';
    HttpUtils::sendError('json', $errorMessage);
    exit;
}
$user = SessionUtils::getUser();

// validate subplant
$queryParams = HttpUtils::getRequestValues(array('warehouse_id'), 'all');
$warehouseId = $queryParams['warehouse_id'];
$validAggregateSubplants = array('all', 'local', 'other');

if (!RequestParamProcessor::validateSubplantId($warehouseId) && !in_array($warehouseId, $validAggregateSubplants)) {
    $params = array(
        'warehouse_id' => empty($warehouseId) ? 'warehouse_id is empty!!' : "Unknown warehouse_id [$warehouseId]!"
    );
    HttpUtils::sendError('json', 'Invalid params!', $params, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
}

try {
    $db = PostgresqlDatabase::getInstance();

    $warehouseIds = array();
    $querySubplant = null;
    if ($warehouseId === 'all') {
        $querySubplant = 'location_warehouse_id = ANY($1)';
        $warehouseIds = array_merge($warehouseIds, $user->gua_subplants);
    } elseif ($warehouseId === 'other') {
        $querySubplant = PlantIdHelper::usesLocationCell() ?
            'location_area_name LIKE \'STOCK DI P%\' AND location_warehouse_id = ANY($1)' :
            'location_warehouse_id = ANY($1)';
        $warehouseIds = PlantIdHelper::usesLocationCell() ?
            array_merge($warehouseIds, $user->gua_subplants) :
            array_filter($user->gua_subplants, function($warehouseId) {
                return !in_array($warehouseId, PlantIdHelper::${'SUBPLANTS_LOC_' . PlantIdHelper::getCurrentPlant()});
            });
    } elseif ($warehouseId === 'local') {
        $querySubplant = PlantIdHelper::usesLocationCell() ?
            'location_area_name NOT LIKE \'STOCK DI P%\' AND location_warehouse_id = ANY($1)' :
            'location_warehouse_id = ANY($1)';
        $warehouseIds = PlantIdHelper::usesLocationCell() ?
            array_merge($warehouseIds, $user->gua_subplants) :
            array_filter($user->gua_subplants, function($warehouseId) {
                return in_array($warehouseId, PlantIdHelper::${'SUBPLANTS_LOC_' . PlantIdHelper::getCurrentPlant()});
            });
    } else {
        $querySubplant = 'location_warehouse_id = ANY($1)';
        $warehouseIds[] = $warehouseId;
    }
    if (PlantIdHelper::usesLocationCell()) {
        $warehouseIds = array_map(function ($warehouseId) {
            return PlantIdHelper::toSubplantId($warehouseId);
        }, $warehouseIds);
    }
    $query = "
        SELECT
            location_warehouse_id,
            location_area_name,
            location_area_id,
            SUM(pallet_count) AS pallet_count,
            SUM(total_quantity) AS total_quantity
        FROM summary_pallets_with_location_by_line 
        WHERE total_quantity > 0 
          AND $querySubplant
        GROUP BY location_warehouse_id, location_area_name, location_area_id
        ORDER BY location_warehouse_id, location_area_id
        ";
    $cursor = $db->parameterizedQuery($query, array($warehouseIds));

    $summaries = array();
    while ($row = pg_fetch_assoc($cursor)) {
        $row['total_quantity'] = intval($row['total_quantity']);
        $row['pallet_count'] = intval($row['pallet_count']);
        $summaries[] = $row;
    }

    HttpUtils::sendJsonResponse($summaries, '', $user->gua_kode);
} catch (PostgresqlDatabaseException $e) {
    $errorMessage = $e->getMessage();
    $additionalInfo = array(
        'query' => $e->getQuery(),
        'db_message' => $e->getOriginalMessage()
    );
    HttpUtils::sendError('json', $errorMessage, $additionalInfo);
}
