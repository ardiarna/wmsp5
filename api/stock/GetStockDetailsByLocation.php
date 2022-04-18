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
$queryParams = HttpUtils::getRequestValues(array('warehouse_id', 'area_no'));

$paramErrors = array();
$warehouseId = $queryParams['warehouse_id'];
if (!RequestParamProcessor::validateSubplantId($warehouseId)) {
    $paramErrors['warehouse_id'] = empty($warehouseId) ? 'Koke Gudang kosong!' : "Koke Gudang [$warehouseId] tidak dikenal!";
}
$areaNo = $queryParams['area_no'];
if (empty($areaNo)) {
    $paramErrors['area_no'] = 'Kode Area kosong!';
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
SELECT * 
FROM summary_pallets_with_location_by_line 
WHERE total_quantity > 0
  AND location_warehouse_id = $1 
  AND location_area_id = $2
ORDER BY location_line_no, motif_dimension, motif_id, motif_name';

    $cursor = $db->parameterizedQuery($query, array($warehouseId, $areaNo));
    $summaries = array();
    while ($row = pg_fetch_assoc($cursor)) {
        $row['total_quantity'] = intval($row['total_quantity']);
        $row['pallet_count'] = intval($row['pallet_count']);
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
