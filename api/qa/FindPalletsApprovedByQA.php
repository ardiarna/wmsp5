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

$requests = HttpUtils::getRequestValues(array('rlt_no'));

$requestErrors = array();
$r_rltNo = trim($requests['rlt_no']);
if (empty($r_rltNo)) {
    $requestErrors['rlt_no'] = 'rlt_no is empty!';
}

if (count($requestErrors) > 0) {
    HttpUtils::sendError($mode, 'Invalid requests!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();

    $query = '
SELECT io_plan_kode AS location_subplant,
    ima.ket_area AS location_area_name,
    iml_kd_area AS location_area_no,
    iml_no_lok AS location_line_no,
    io_kd_lok AS location_id,
    io_tgl AS location_since,
  
    item.item_kode AS motif_id,
    item.item_nama AS motif_name,
    ( SELECT category.category_nama
           FROM category
          WHERE category.category_kode = substr(item.item_kode, 1, 2)) AS motif_dimension,
    tanggal AS created_at,
    pallet_no,
    line,
    regu AS creator_group,
    shift AS creator_shift,
    tbl_sp_hasilbj.size,
    tbl_sp_hasilbj.shade AS shading,
    tbl_sp_hasilbj.last_qty AS current_quantity
   FROM tbl_sp_hasilbj
     INNER JOIN item ON tbl_sp_hasilbj.item_kode = item.item_kode
     LEFT JOIN inv_opname io ON tbl_sp_hasilbj.pallet_no = io.io_no_pallet
     LEFT JOIN inv_master_lok_pallet iml on io.io_plan_kode = iml.iml_plan_kode and io.io_kd_lok = iml.iml_kd_lok
     LEFT JOIN inv_master_area ima on iml.iml_plan_kode = ima.plan_kode and iml.iml_kd_area = ima.kd_area
   WHERE rkpterima_no = $1
   ';

    $rltNo = $r_rltNo;
    $params = array($rltNo);
    $cursor = $db->parameterizedQuery($query, $params);

    if (pg_num_rows($cursor) === 0) {
        $requestErrors['rlt_no'] = "Cannot find rlt_no '$rltNo'!";
    }

    if (count($requestErrors) > 0) {
        HttpUtils::sendError($mode, 'Invalid requests!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
        exit;
    }

    $pallets = array();
    while ($row = pg_fetch_assoc($cursor)) {
        $row['line'] = intval($row['line']);
        $row['location_line_no'] = isset($row['location_line_no']) ? intval($row['location_line_no']) : null;
        $row['creator_shift'] = isset($row['creator_shift']) ? intval($row['creator_shift']) : null;
        $row['initial_quantity'] = intval($row['initial_quantity']);
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
