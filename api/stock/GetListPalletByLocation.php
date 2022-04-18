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

// validate request params
$queryParams = HttpUtils::getRequestValues(array('warehouse_id', 'area_no', 'row_no', 'subplant', 'motif_id', 'size', 'shade'));

$paramErrors = array();
$warehouseId = $queryParams['warehouse_id'];
if (!RequestParamProcessor::validateSubplantId($warehouseId)) {
    $paramErrors['warehouse_id'] = empty($warehouseId) ? 'Kode Gudang kosong!' : "Koke Gudang [$warehouseId] tidak dikenal!";
}
$areaNo = $queryParams['area_no'];
if (empty($areaNo)) {
    $paramErrors['area_no'] = 'Kode Area kosong!';
}
$rowNo = $queryParams['row_no'];
if (empty($rowNo)) {
    $paramErrors['row_no'] = 'Nomor Baris kosong!';
}
$subplant = $queryParams['subplant'];
if (empty($subplant)) {
    $paramErrors['subplant'] = 'Subplant kosong!';
}
$motifId = $queryParams['motif_id'];
if (empty($motifId)) {
    $paramErrors['motif_id'] = 'Kode Motif kosong!';
}
$size = $queryParams['size'];
if (empty($size)) {
    $paramErrors['size'] = 'Size kosong!';
}
$shade = $queryParams['shade'];
if (empty($shade)) {
    $paramErrors['shade'] = 'Shade kosong!';
}

if (count($paramErrors) > 0) {
    HttpUtils::sendError('json', 'Invalid params!', $paramErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();

    $queryCheckArea = '
SELECT * FROM inv_master_area 
WHERE plan_kode = $1
  AND kd_area = $2
  LIMIT 1;
    ';
    $cursorCheckArea = $db->parameterizedQuery($queryCheckArea, array($warehouseId, $areaNo));
    $areaExists = pg_num_rows($cursorCheckArea) === 1;
    if (!$areaExists) {
        $paramErrors['area_no'] = "Unknown area no. [$areaNo] in subplant $warehouseId";
    }
    if (count($paramErrors) > 0) {
        HttpUtils::sendError($mode, 'Invalid params!', $paramErrors, HttpUtils::HTTP_RESPONSE_NOT_FOUND);
        exit;
    }

    $query = '
        SELECT a.*
        FROM pallets_with_location a
        WHERE a.last_qty > 0
            AND a.location_subplant = $1 AND a.location_area_no = $2 AND a.location_row_no = $3 
            AND a.subplant = $4 AND a.item_kode = $5 AND a.size = $6 AND a.shade = $7
        ORDER BY a.pallet_no';

    $cursor = $db->parameterizedQuery($query, array($warehouseId, $areaNo, $rowNo, $subplant, $motifId, $size, $shade));
    $summaries = array();
    while ($row = pg_fetch_assoc($cursor)) {
        $row['last_qty'] = intval($row['last_qty']);
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
