<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

SessionUtils::sessionStart();

$requestParams = HttpUtils::getRequestValues(array('mode'), 'xml');
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

$requests = HttpUtils::getRequestValues(array('line_no', 'subplant', 'area_code'));

$requestErrors = array();
$r_areaCode = trim($requests['area_code']);
if (empty($r_areaCode)) {
    $requestErrors['area_code'] = 'area_code is empty!';
}
$r_subplant = trim($requests['subplant']);
if (empty($r_subplant)) {
    $requestErrors['subplant'] = 'subplant is empty!';
} else if (!RequestParamProcessor::validateSubplantId($r_subplant)) {
    $requestErrors['subplant'] = "Invalid subplant [$r_subplant]!";
}
$r_lineNo = trim($requests['line_no']);
$lineNo = null;
if (empty($r_lineNo)) {
    $lineNo = 'all';
} else {
    if (is_numeric($r_lineNo)) {
        $lineNo = intval($r_lineNo);
        if ($lineNo <= 0) {
            $requestErrors['line_no'] = "Invalid line_no [$r_lineNo]!";
        }
    } else if (strtolower($r_lineNo) !== 'all') {
        $requestErrors['line_no'] = "Invalid line_no [$r_lineNo]!";
    }
}

if (count($requestErrors) > 0) {
    HttpUtils::sendError($mode, 'Invalid requests!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();

    $query = "
SELECT pallets_with_location.location_subplant,
    pallets_with_location.location_area_name,
    pallets_with_location.location_area_no,
    pallets_with_location.location_row_no AS location_line_no,
    pallets_with_location.location_id,
    pallets_with_location.location_since,
    (CASE 
      WHEN item.quality IN ('EKONOMI', 'ECONOMI', 'ECO', 'ECONOMY') THEN 'ECO'
      WHEN item.quality IN ('EXPORT', 'EXP') THEN 'EXP'
      ELSE item.quality
      END) AS quality,
    item.item_kode AS motif_id,
    item.item_nama AS motif_name,
    ( SELECT category.category_nama
           FROM category
          WHERE category.category_kode = substr(item.item_kode, 1, 2)) AS motif_dimension,
    tanggal AS created_at,
    pallet_no,
    line,
    shift AS creator_shift,
    pallets_with_location.size,
    pallets_with_location.shade AS shading,
    pallets_with_location.last_qty AS current_quantity
   FROM pallets_with_location
     JOIN item ON pallets_with_location.item_kode = item.item_kode
   WHERE last_qty > 0 AND location_subplant = $1 AND location_area_no = $2
   ";

    $subplant = $r_subplant;
    $areaCode = $r_areaCode;
    $params = array($subplant, $areaCode);

    if ($lineNo !== 'all') {
        $params[] = $lineNo;
        $query .= ' AND location_row_no = $3 ORDER BY pallet_no';
    } else {
        $query .= 'ORDER BY location_line_no, pallet_no';
    }
    $cursor = $db->parameterizedQuery($query, $params);

    $pallets = array();
    while ($row = pg_fetch_assoc($cursor)) {
        $row['line'] = intval($row['line']);
        $row['location_line_no'] = intval($row['location_line_no']);
        $row['creator_shift'] = isset($row['creator_shift']) ? intval($row['creator_shift']) : null;
        $row['current_quantity'] = intval($row['current_quantity']);
        if($row['quality'] == "KW4") {
            $row['quality'] =  "LOKAL";
            $row['motif_name'] = str_replace("KW4","LOKAL",$row['motif_name']);
        } else if($row['quality'] == "KW5") {
            $row['quality'] =  "BBM SQUARING";
            $row['motif_name'] = str_replace("KW5","BBM SQUARING",$row['motif_name']);
        } else if($row['quality'] == "KW6") {
            $row['quality'] =  "BBM OVERSIZE";
            $row['motif_name'] = str_replace("KW6","BBM OVERSIZE",$row['motif_name']);
        }
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
