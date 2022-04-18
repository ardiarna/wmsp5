<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

use Utils\Env;

SessionUtils::sessionStart();

if (!SessionUtils::isAuthenticated()) {
    HttpUtils::sendError('json', 'Belum terautentikasi!', array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}
$user = SessionUtils::getUser();
$requests = HttpUtils::getRequestValues(array('subplant', 'motif_name', 'production_date_from', 'production_date_to', 'creator_shift', 'quality', 'line'));

$requestErrors = array();

// validate request
$subplant = trim($requests['subplant']);
if (empty($subplant)) {
    $requestErrors['subplant'] = 'subplant kosong!';
} elseif (!RequestParamProcessor::validateSubplantId($subplant) && $subplant !== 'all') {
    $requestErrors['subplant'] = "subplant $subplant tidak dikenal!";
}

$us_productionDateFrom = $requests['production_date_from'];
$productionDateFrom = null;
if (!isset($us_productionDateFrom)) {
    $requestErrors['production_date_from'] = 'Empty date!';
} else {
    try {
        $productionDateFrom = RequestParamProcessor::getLocalDate($us_productionDateFrom);
    } catch (InvalidArgumentException $e) {
        $requestErrors['production_date_from'] = 'Invalid date [' . $us_productionDateFrom . ']';
    }
}

$us_productionDateTo = $requests['production_date_to'];
$productionDateTo = null;
if (!isset($us_productionDateTo)) {
    $requestErrors['production_date_to'] = 'Empty date!';
} else {
    try {
        $productionDateTo = RequestParamProcessor::getLocalDate($us_productionDateTo);
    } catch (InvalidArgumentException $e) {
        $requestErrors['production_date_to'] = 'Invalid date [' . $us_productionDateTo . ']';
    }
}
$motifName = trim($requests['motif_name']);
$creatorShift = trim((string)$requests['creator_shift']);
$quality = $requests['quality'];
$line = $requests['line'];

if (empty($creatorShift)) {
    $requestErrors['shift'] = "creator_shift is empty!";
} elseif (!in_array($creatorShift, array('1', '2', '3'), true) && $creatorShift !== 'all') {
    $requestErrors['shift'] = "Unknown creator_shift [$creatorShift]!";
}

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
    $params[] = $productionDateFrom->format('Y-m-d');
    $params[] = $productionDateTo->format('Y-m-d');

    $optionalParamNo = 4;
    $motifFilter = '1=1';
    if (!empty($motifName)) {
        $motifFilter = 'item_nama ILIKE \'%\' || $' . $optionalParamNo++ . ' || \'%\'';
        // replace whitespace with something.
        $motifName = preg_replace('/\s+/', '%', $motifName);
        $params[] = $motifName;
    }

    $creatorShiftFilter = '1=1';
    if ($creatorShift !== 'all') {
        $creatorShiftFilter = 'shift = $' . $optionalParamNo++;
        $params[] = $creatorShift;
    }

    $qualityFilter = '1=1';
    if ($quality !== 'all') {
        $qualityFilter = 'item_master.quality = $' . $optionalParamNo++;
        $params[] = $quality;
    }

    $lineFilter = '1=1';
    if ($line !== 'all') {
        $lineFilter = 'line = $' . $optionalParamNo;
        $params[] = $line;
    }

    $db = PostgresqlDatabase::getInstance();
    $query = "SELECT
  hasilbj.pallet_no,
  item_master.item_nama                                            AS motif_name,
  item_master.item_kode                                            AS motif_id,
  category_nama                                                    AS motif_dimension,
  item_master.quality,

  hasilbj.qty                                                      AS initial_quantity,
  hasilbj.last_qty                                                 AS current_quantity,
  hasilbj.quality                                                  AS quality,
  hasilbj.shade                                                    AS shading,
  hasilbj.size                                                     AS size,
  hasilbj.line                                                     AS line,

  hasilbj.shift                                                    AS creator_shift,
  hasilbj.regu                                                     AS creator_group,

  hasilbj.status_plt                                               AS status,

  hasilbj.tanggal                                                  AS production_date,
  (CASE
    WHEN hasilbj.update_tran IS NOT NULL THEN hasilbj.update_tran
     -- use default values based on the start time of every shift
    WHEN hasilbj.shift = 1 THEN (hasilbj.create_date + time '07:00:00')
    WHEN hasilbj.shift = 2 THEN (hasilbj.create_date + time '15:00:00')
    WHEN hasilbj.shift = 3 THEN (hasilbj.create_date + time '23:00:00')
    ELSE (hasilbj.create_date + time '00:00:00')
 END)                                                              AS updated_at,
  io.io_kd_lok                                                     AS location_id
FROM tbl_sp_hasilbj hasilbj
  JOIN item item_master ON hasilbj.item_kode = item_master.item_kode
  JOIN category ON LEFT(item_master.item_kode, 2) = category.category_kode
  LEFT JOIN inv_opname io ON hasilbj.pallet_no = io.io_no_pallet
WHERE hasilbj.subplant = ANY($1)
  AND tanggal BETWEEN $2 AND $3
  AND status_plt IN ('R', 'B')
  AND block_ref_id IS NULL
  AND $motifFilter
  AND $creatorShiftFilter
  AND $qualityFilter
  AND $lineFilter
  AND last_qty > 0
ORDER BY tanggal, subplant, pallet_no";
    $res = $db->parameterizedQuery($query, $params);

    $response = array();
    while ($queryResult = pg_fetch_assoc($res)) {
        $queryResult = QueryResultConverter::toInt($queryResult, array('initial_quantity', 'current_quantity'));

        $response[] = $queryResult;
    }

    $db->close();
    HttpUtils::sendJsonResponse($response, $params,$user->gua_kode);
} catch (PostgresqlDatabaseException $e) {
    HttpUtils::sendError('json', (string)$e,
        Env::isDebug() ? $e->getTrace() : array()
    );
}
