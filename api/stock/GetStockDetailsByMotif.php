<?php
require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

SessionUtils::sessionStart();

$requestParams = HttpUtils::getRequestValues(array('mode'), 'json');
$mode = $requestParams['mode'];
if (!HttpUtils::isValidMode($mode)) {
    HttpUtils::sendError($mode, '', array(), HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
}

if (!SessionUtils::isAuthenticated()) {
    // print error
    $errorMessage = 'You are not authenticated!';
    HttpUtils::sendError($mode, $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}
$user = SessionUtils::getUser();

// validate request params
$queryParams = HttpUtils::getRequestValues(array('motif_ids', 'subplant', 'warehouse_id'));
$requestErrors = array();

$motifIds = $queryParams['motif_ids'];
if (!is_array($motifIds)) {
    $requestErrors['motif_ids'] = 'motif_ids should be an array!';
} else if (count($motifIds) <= 0) {
    $requestErrors['motif_ids'] = 'motif_ids is empty!';
}

$warehouseId = trim($queryParams['warehouse_id']);
if (empty($warehouseId)) {
    $warehouseId = 'all';
}

$subplant = $queryParams['subplant'];
if (is_string($subplant)) {
    $subplant = trim($queryParams['subplant']);
    if (!RequestParamProcessor::validateSubplantId($subplant) && !in_array($subplant, PlantIdHelper::getAggregateQueryTypes())) {
        if (empty($subplant)) {
            $requestErrors['subplant'] = 'subplant is empty!';
        } else {
            $requestErrors['subplant'] = "Unknown subplant [$subplant]!";
        }
    }
} elseif (!is_array($subplant)) {
    $requestErrors['subplant'] = 'unknown subplant type ' . gettype($subplant);
}

if (count($requestErrors) > 0) {
    HttpUtils::sendError($mode, 'Invalid params!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();

    $warehouseIds = $warehouseId === 'all' ? $user->gua_subplants : array($warehouseId);
    if (PlantIdHelper::usesLocationCell()) {
        $warehouseIds = array_map(function ($subplant) {
            return PlantIdHelper::toSubplantId($subplant);
        }, $warehouseIds);
    }
    $params = array($motifIds, $warehouseIds);
    $subplantFilter = '';

    if ($subplant === 'all') {
        // no param added
    } else if (is_array($subplant)) {
        $subplantFilter = ' AND production_subplant = ANY($3)';
        $params[] = $subplant;
    } else if ($subplant === 'local') {
        $subplantFilter = ' AND (CASE WHEN production_subplant IN (\'4\', \'5\') THEN production_subplant || \'A\' ELSE production_subplant END) = ANY($3)';
        $params[] = $user->gua_subplant_handover;
    } else if ($subplant === 'other') {
        $subplantFilter = ' AND LEFT(production_subplant, 1) <> $3';
        $params[] = (string)PlantIdHelper::getCurrentPlant();
    } else {
        $subplantFilter = ' AND production_subplant = $3';
        $params[] = $subplant;
    }

    $query = "SELECT *, 0 AS block_qty
        FROM summary_stock_by_motif_location 
        WHERE motif_id = ANY($1)
        AND location_warehouse_id = ANY($2) $subplantFilter
        ORDER BY location_warehouse_id, location_area_id, location_line_no, pallet_status, size, shading";

    $cursor = $db->parameterizedQuery($query, $params);
    $summaries = array();
    while ($row = pg_fetch_assoc($cursor)) {
        $row['total_quantity'] = intval($row['total_quantity']);
        $row['pallet_count'] = intval($row['pallet_count']);
        $row['block_qty'] = intval($row['block_qty']);
        $row['ava_qty'] = intval($row['total_quantity'])-intval($row['block_qty']);
        $summaries[] = $row;
    }

    switch($mode) {
        case HttpUtils::MODE_XML:
            HttpUtils::sendXmlResponse($summaries);
            break;
        case HttpUtils::MODE_JSON:
            HttpUtils::sendJsonResponse($summaries, '', $user->gua_kode);
            break;
    }
} catch (PostgresqlDatabaseException $e) {
    $errorMessage = $e->getMessage();
    $additionalInfo = array(
        'query' => $e->getQuery(),
        'db_message' => $e->getOriginalMessage()
    );
    HttpUtils::sendError($mode, $errorMessage, $additionalInfo);
}
