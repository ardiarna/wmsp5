<?php
require_once '../../classes/autoloader.php';

session_start();

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
if (!RequestParamProcessor::validateSubplantId($subplant) && $subplant !== 'all') {
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
  SELECT production_subplant, 
    motif_id, motif_dimension, motif_name, 
    quality, size, shading, 
    sum(pallet_count) AS pallet_count, sum(current_quantity) AS current_quantity
  FROM summary_pallets_available_for_sales 
  WHERE current_quantity > 0 AND motif_id = ANY($1)';
    $q_motifIds = '{' . implode(',', $motifIds) . '}';
    $params = array($q_motifIds);

    if ($subplant !== 'all') {
        $query .= ' AND production_subplant = $2';
        if (!PlantIdHelper::hasSubplants()) {
            $subplant = $subplant[0];
        }
        $params[] = $subplant;
    }

    $query .= '
    GROUP BY production_subplant, motif_id, motif_dimension, motif_name, quality, size, shading
    ORDER BY production_subplant, motif_dimension, motif_id, size, shading';

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
