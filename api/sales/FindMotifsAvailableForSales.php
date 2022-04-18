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

$requestErrors = array();
$queryParams = HttpUtils::getRequestValues(array('subplant'));
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

    $queryProductionSubplant = null;
    if ($subplant === 'all') {
        $queryProductionSubplant = '';
    } else if ($subplant === 'other') {
        $queryProductionSubplant = ' AND LEFT(production_subplant, 1) <> $2';
    } else {
        $queryProductionSubplant = ' AND production_subplant = $2';
    }
    $query = "
SELECT production_subplant, quality, motif_dimension, motif_id, motif_name, SUM(pallet_count) AS pallet_count, SUM(current_quantity) AS current_quantity 
FROM summary_motifs_available_for_sales 
WHERE current_quantity > 0
  AND location_subplant = ANY($1)
  $queryProductionSubplant
GROUP BY production_subplant, quality, motif_dimension, motif_id, motif_name
ORDER BY production_subplant, quality, motif_dimension, motif_name";

    $params = array($user->gua_subplants);
    if ($subplant === 'other') {
        $params[] = (string) PlantIdHelper::getCurrentPlant();
    } else if ($subplant !== 'all' && $subplant !== 'other') {
        if (!PlantIdHelper::hasSubplants() && in_array($subplant, PlantIdHelper::${'SUBPLANTS_PROD_' . PlantIdHelper::getCurrentPlant()})) {
            $subplant = $subplant[0];
        }
        $params[] = $subplant;
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
