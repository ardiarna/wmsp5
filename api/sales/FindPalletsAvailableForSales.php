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
$queryParams = HttpUtils::getRequestValues(array('motif_ids', 'subplant'));
$requestErrors = array();

$motifIds = $queryParams['motif_ids'];
if (!is_array($motifIds)) {
    $requestErrors['motif_ids'] = 'motif_ids should be an array!';
} else if (count($motifIds) <= 0) {
    $requestErrors['motif_ids'] = 'motif_ids is empty!';
}

$subplant = trim($queryParams['subplant']);
if (!RequestParamProcessor::validateSubplantId($subplant) && $subplant !== 'all' && $subplant !== 'other') {
    if (empty($subplant)) {
        $requestErrors['subplant'] = 'subplant is empty!';
    } else {
        $requestErrors['subplant'] = "Unknown subplant [$subplant]!";
    }
}

if (count($requestErrors) > 0) {
    HttpUtils::sendError($mode, 'Invalid params!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();

    $query = '
SELECT * 
FROM summary_sku_available_for_sales 
WHERE motif_id = ANY($1)
  AND location_subplant = ANY($2)';
    $params = array($motifIds, $user->gua_subplants);

    if ($subplant !== 'all' && $subplant !== 'other') {
        $query .= ' AND production_subplant = $3';
        if (!PlantIdHelper::hasSubplants() && in_array($subplant, PlantIdHelper::${'SUBPLANTS_PROD_' . PlantIdHelper::getCurrentPlant()})) {
            $subplant = $subplant[0];
        }
        $params[] = $subplant;
    } else if ($subplant === 'other') {
        $query .= ' AND LEFT(production_subplant, 1) <> $3';
        $params[] = (string) PlantIdHelper::getCurrentPlant();
    }

    $cursor = $db->parameterizedQuery($query, $params);
    $summaries = array();
    while ($row = pg_fetch_assoc($cursor)) {
        $row['current_quantity'] = intval($row['current_quantity']);
        $row['pallet_count'] = intval($row['pallet_count']);
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
