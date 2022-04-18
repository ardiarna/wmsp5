<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

use Utils\Env;

SessionUtils::sessionStart();

if (!SessionUtils::isAuthenticated()) {
    HttpUtils::sendError('json', 'Belum terautentikasi!', array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}
$user = SessionUtils::getUser();
$requests = HttpUtils::getRequestValues(array('subplant', 'quality', 'size', 'shading', 'motif', 'lokasi'));

$requestErrors = array();

// validate request
$subplant = trim($requests['subplant']);
if (empty($subplant)) {
    $requestErrors['subplant'] = 'subplant kosong!';
} elseif (!RequestParamProcessor::validateSubplantId($subplant) && $subplant !== 'all') {
    $requestErrors['subplant'] = "subplant $subplant tidak dikenal!";
}

$quality = trim($requests['quality']);
$size = trim($requests['size']);
$shading = trim($requests['shading']);
$motif = trim($requests['motif']);
$lokasi = trim($requests['lokasi']);

if (!empty($requestErrors)) {
    HttpUtils::sendError('json', 'Kesalahan pada permintaan data!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $params = array();
    if ($subplant === 'all') {
        $params[] = $user->gua_subplant_handover;
    } else {
        $params[] = array($subplant);
    }
    
    $optionalParamNo = 2;
    
    $qualityFilter = '1=1';
    if ($quality !== 'all') {
        if($quality == 'EXP') {
            $qualityFilter = 'item.quality = $' . $optionalParamNo++;
            $params[] = 'EXPORT';
        } else if($quality == 'ECO') {
            $qualityFilter = '(item.quality = $' . $optionalParamNo++ .' OR item.quality = $' . $optionalParamNo++ .')';
            $params[] = 'ECONOMY';
            $params[] = 'EKONOMI';
        } 
    }

    $sizeFilter = '1=1';
    if ($size !== 'all') {
        $sizeFilter = 'tbl_sp_hasilbj.size = $' . $optionalParamNo++;
        $params[] = $size;
    }

    $shadingFilter = '1=1';
    if (!empty($shading)) {
        $shadingFilter = 'tbl_sp_hasilbj.shade = $' . $optionalParamNo++;
        $params[] = $shading;
    }

    $motifFilter = '1=1';
    if (!empty($motif)) {
        $motifFilter = 'upper(item.item_nama) LIKE \'%\' || $' . $optionalParamNo++ . ' || \'%\'';
        $params[] = strtoupper($motif);
    }

    $lokasiFilter = '1=1';
    if (!empty($lokasi)) {
        $lokasiFilter = '(upper(inv_master_lok_pallet.iml_kd_area) LIKE \'%\' || $' . $optionalParamNo++ . ' || \'%\' OR upper(inv_master_area.ket_area) LIKE \'%\' || $' . $optionalParamNo++ . ' || \'%\')';
        $params[] = strtoupper($lokasi);
        $params[] = strtoupper($lokasi);
    }

    $db = PostgresqlDatabase::getInstance();
    $query = "SELECT tbl_sp_hasilbj.subplant AS production_subplant, tbl_sp_hasilbj.pallet_no, tbl_sp_hasilbj.tanggal, item.item_nama AS motif_name, tbl_sp_hasilbj.size, tbl_sp_hasilbj.shade AS shading, tbl_sp_hasilbj.last_qty AS qty, 
        CASE
            WHEN item.quality = 'EXPORT' THEN 'EXP'
            WHEN item.quality = 'ECONOMY' OR item.quality = 'EKONOMI' THEN 'ECO'
            ELSE item.quality
        END AS quality,
        inv_master_lok_pallet.iml_kd_area ||' - '|| inv_master_area.ket_area AS area,
        inv_master_lok_pallet.iml_no_lok AS baris,
        CURRENT_DATE - tbl_sp_hasilbj.tanggal AS aging
    FROM inv_opname
    JOIN tbl_sp_hasilbj ON (tbl_sp_hasilbj.pallet_no = inv_opname.io_no_pallet)
    JOIN inv_master_lok_pallet ON (inv_opname.io_kd_lok = inv_master_lok_pallet.iml_kd_lok AND inv_opname.io_plan_kode = inv_master_lok_pallet.iml_plan_kode)
    JOIN inv_master_area ON (inv_master_lok_pallet.iml_kd_area = inv_master_area.kd_area AND inv_master_lok_pallet.iml_plan_kode = inv_master_area.plan_kode)
    JOIN item ON (tbl_sp_hasilbj.item_kode = item.item_kode)
    WHERE tbl_sp_hasilbj.last_qty > 0
    AND tbl_sp_hasilbj.pallet_no NOT IN (SELECT pallet_no FROM item_gbj_stockblock WHERE order_status = 'O' OR order_status = 'S')
    AND tbl_sp_hasilbj.subplant = ANY($1)
    AND $qualityFilter
    AND $sizeFilter
    AND $shadingFilter
    AND $motifFilter
    AND $lokasiFilter
    ORDER BY tbl_sp_hasilbj.tanggal, tbl_sp_hasilbj.pallet_no";

    $res = $db->parameterizedQuery($query, $params);

    $response = array();
    while ($queryResult = pg_fetch_assoc($res)) {
        $queryResult = QueryResultConverter::toInt($queryResult, array('qty'));
        $response[] = $queryResult;
    }

    $db->close();
    HttpUtils::sendJsonResponse($response, $user->gua_kode);
} catch (PostgresqlDatabaseException $e) {
    HttpUtils::sendError('json', (string)$e,
        Env::isDebug() ? $e->getTrace() : array()
    );
}
