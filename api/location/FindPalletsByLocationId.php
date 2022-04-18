<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

SessionUtils::sessionStart();

$requestParams = HttpUtils::getRequestValues(array('mode'), 'xml');
$mode = $requestParams['mode'];
if (!HttpUtils::isValidMode($mode)) {
    HttpUtils::sendError($mode, '');
    exit;
}

if (!SessionUtils::isAuthenticated()) {
    // print error
    $errorMessage = 'You are not authenticated!';
    HttpUtils::sendError($mode, $errorMessage);
    exit;
}
$user = SessionUtils::getUser();

$requests = HttpUtils::getRequestValues(array('location_id'));
$requestErrors = array();

// validate location_id
$r_locationId = strtoupper(trim($requests['location_id']));
if (empty($r_locationId)) {
    $requestErrors['location_id'] = 'location_id empty!';
}
$regexMatches = array();
if (!preg_match_all(PlantIdHelper::locationRegex(), $r_locationId, $regexMatches)) {
    $requestErrors['location_id'] = "location_id $r_locationId invalid!";
}
if (count($requestErrors) > 0) {
    HttpUtils::sendError($mode, 'Invalid requests!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

// check subplant authorization
$subplant = PlantIdHelper::usesLocationCell() ? PlantIdHelper::toSubplantId($regexMatches[1][0]) : $regexMatches[1][0];
if (!RequestParamProcessor::validateSubplantId($subplant)) {
    $userid = $user->gua_kode;
    $errorMessage = "User $userid tidak dapat mengakses data subplant [$subplant]!";
    HttpUtils::sendError($mode, $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
}

try {
    $db = PostgresqlDatabase::getInstance();

    $query = null;
    if (PlantIdHelper::usesLocationCell()) {
        $query = '
SELECT pallets_with_location.location_subplant,
    pallets_with_location.location_area_name,
    pallets_with_location.location_area_no,
    pallets_with_location.location_cell_no,
    pallets_with_location.location_row_no AS location_line_no,
    pallets_with_location.location_id,
    pallets_with_location.location_since,
  
    item.item_kode AS motif_id,
    item.item_nama AS motif_name,
    ( SELECT category.category_nama
           FROM category
          WHERE category.category_kode = substr(item.item_kode, 1, 2)) AS motif_dimension,
    tanggal AS created_at,
    pallet_no,
    line,
    shift AS creator_shift,
    pallets_with_location.quality AS quality,
    pallets_with_location.size,
    pallets_with_location.shade AS shading,
    pallets_with_location.last_qty AS current_quantity
   FROM pallets_with_location
     JOIN item ON pallets_with_location.item_kode = item.item_kode
   WHERE location_id = $1
   ';
    } else {
        $query = '
SELECT pallets_with_location.location_subplant,
    pallets_with_location.location_area_name,
    pallets_with_location.location_area_no,
    pallets_with_location.location_row_no AS location_line_no,
    pallets_with_location.location_id,
    pallets_with_location.location_since,
  
    item.item_kode AS motif_id,
    item.item_nama AS motif_name,
    ( SELECT category.category_nama
           FROM category
          WHERE category.category_kode = substr(item.item_kode, 1, 2)) AS motif_dimension,
    tanggal AS created_at,
    pallet_no,
    line,
    shift AS creator_shift,
    pallets_with_location.quality AS quality,
    pallets_with_location.size,
    pallets_with_location.shade AS shading,
    pallets_with_location.last_qty AS current_quantity
   FROM pallets_with_location
     JOIN item ON pallets_with_location.item_kode = item.item_kode
   WHERE pallets_with_location.last_qty > 0 AND location_id = $1';
    }
    $locationId = $r_locationId;
    $cursor = $db->parameterizedQuery($query, array($r_locationId));

    $pallets = array();
    while ($row = pg_fetch_assoc($cursor)) {
        $row['line'] = isset($row['line']) ? intval($row['line']) : null;
        $row['location_line_no'] = intval($row['location_line_no']);
        if (PlantIdHelper::usesLocationCell()) {
            $row['location_cell_no'] = intval($row['location_cell_no']);
        }
        $row['creator_shift'] = isset($row['creator_shift']) ? intval($row['creator_shift']) : null;
        $row['current_quantity'] = intval($row['current_quantity']);

        $pallets[] = $row;
    }

    switch ($mode) {
        case HttpUtils::MODE_XML:
            HttpUtils::sendXmlResponse($pallets);
            break;
        case HttpUtils::MODE_JSON:
            HttpUtils::sendJsonResponse($pallets, '', $user->gua_kode);
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
