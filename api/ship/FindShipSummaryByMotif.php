<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

SessionUtils::sessionStart();

$requestParams = HttpUtils::getRequestValues(array('mode'), 'json');
$mode = $requestParams['mode'];
if (!HttpUtils::isValidMode($mode)) {
    HttpUtils::sendError($mode, '', array(), HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
}

try {
    $logger = \Utils\FileLog::getInstance();
} catch (Exception $e) {
    $errorMessage = 'Cannot open app log file! Reason: ' . $e->getMessage();
    error_log($errorMessage);
    HttpUtils::sendError($mode, $errorMessage, array('log_channel' => 'app'), HttpUtils::HTTP_RESPONSE_SERVER_ERROR);
}

if (!SessionUtils::isAuthenticated()) {
    // print error
    $errorMessage = 'You are not authenticated!';
    HttpUtils::sendError($mode, $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}
$user = SessionUtils::getUser();

// validate request params
$queryParams = HttpUtils::getRequestValues(array('subplant', 'from_date', 'to_date', 'ship_type', 'ship_category'));
$requestErrors = array();

$subplant = trim($queryParams['subplant']);
if (!RequestParamProcessor::validateSubplantId($subplant) && $subplant !== 'all') {
    if (empty($subplant)) {
        $requestErrors['subplant'] = 'subplant is empty!';
    } else {
        $requestErrors['subplant'] = "Unknown subplant [$subplant]!";
    }
}
try {
    $fromDate = RequestParamProcessor::getLocalDate($queryParams['from_date']);
} catch (InvalidArgumentException $e) {
    $requestErrors['from_date'] = $e->getMessage();
}
try {
    $toDate = RequestParamProcessor::getLocalDate($queryParams['to_date']);
} catch (InvalidArgumentException $e) {
    $requestErrors['to_date'] = $e->getMessage();
}
$shipType = $queryParams['ship_type'];
if (!in_array($shipType, array('all', 'Regular', 'Lokal'))) {
    if (empty($shipType)) {
        $requestErrors['ship_type'] = 'ship_type is empty!';
    } else {
        $requestErrors['ship_type'] = "unknown ship_type [$shipType]!";
    }
}

$shipCategory = $queryParams['ship_category'];
if (empty($shipCategory)) {
    $requestErrors['ship_category'] = 'ship_category is empty!';
}

if (count($requestErrors) > 0) {
    HttpUtils::sendError($mode, 'Invalid params!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}
// end of validation.

try {
    $db = PostgresqlDatabase::getInstance();

    // validate stuff that require access to DB
    if ($shipCategory !== 'all') {
        $queryShipCategories = array();
    }

    $querySummary = '
SELECT 
  subplant,
  ship_date,
  ship_category,
  ship_type,
  motif_id,
  motif_name,
  motif_dimension,
  quality,
  0 AS pallet_count,
  SUM(total_quantity) AS total_quantity
FROM summary_shipping_by_category_sku
WHERE subplant = ANY($1) AND ship_date >= $2 AND ship_date <= $3';

    // prepare params
    $q_subplant = array();
    if ($subplant === 'all') {
        $q_subplant = array_merge($user->gua_subplants);
    } else {
        $q_subplant = array($subplant);
    }
    $params = array(
        $q_subplant,
        $fromDate->format('Y-m-d'),
        $toDate->format('Y-m-d')
    );
    if ($shipCategory !== 'all') {
        $querySummary .= ' AND ship_category = $4';
        $params[] = $shipCategory;
    }
    $querySummary .= ' GROUP BY subplant, ship_date, ship_category, ship_type, motif_id, motif_name, motif_dimension, quality';
    // end of query

    $cursor = $db->parameterizedQuery($querySummary, $params);
    $summaries = array();
    while ($row = pg_fetch_assoc($cursor)) {
        $row['total_quantity'] = intval($row['total_quantity']);
        $row['pallet_count'] = intval($row['pallet_count']);
        $summaries[] = $row;
    }
    $queryLastUpdatedAt = "SELECT mv_last_updated_at FROM meta_mv_refresh WHERE mv_name = 'summary_shipping_by_category_sku'";
    $resLastUpdatedAt = $db->rawQuery($queryLastUpdatedAt);
    $lastUpdatedAt = date(PostgresqlDatabase::PGSQL_DATETIME_LOCAL_FORMAT);
    if (pg_num_rows($resLastUpdatedAt) > 0) {
        $rowLastUpdatedAt = pg_fetch_row($resLastUpdatedAt, null, PGSQL_NUM);
        $lastUpdatedAt = $rowLastUpdatedAt[0];
    }
    $db->close();

    $result = array(
        'data' => $summaries,
        'last_updated_at' => $lastUpdatedAt
    );
    switch($mode) {
        case HttpUtils::MODE_XML:
            HttpUtils::sendXmlResponse($result);
            break;
        case HttpUtils::MODE_JSON:
            HttpUtils::sendJsonResponse($result, '', $user->gua_kode);
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
