<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

use Security\RoleAcl;
use Utils\Env;
use Model\PalletDowngrade;

SessionUtils::sessionStart();

if (!SessionUtils::isAuthenticated()) {
    HttpUtils::sendError('json', 'Belum terautentikasi!', array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}
$user = SessionUtils::getUser();

// authorize
$authorized = !empty($user->gua_subplant_handover);
if ($authorized) {
    // check role
    // for now only allow kabag and above to see the data.
    $allowedRoles = RoleAcl::downgradePallets();
    $authorized = UserRole::hasAnyRole($allowedRoles);
}

if (!$authorized) {
    $errorMessage = 'You are not authorized to access downgrade data!';
    HttpUtils::sendError('json', $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}

$requests = HttpUtils::getRequestValues(array('subplant', 'date_from', 'date_to', 'reason', 'status', 'type'));
$requestErrors = array();

// validate request
$subplant = trim($requests['subplant']);
if (empty($subplant)) {
    $requestErrors['subplant'] = 'subplant kosong!';
} elseif (!RequestParamProcessor::validateSubplantId($subplant) && $subplant !== 'all') {
    $requestErrors['subplant'] = "subplant $subplant tidak dikenal!";
}

$us_dateFrom = $requests['date_from'];
$dateFrom = null;
if(!isset($us_dateFrom)) {
    $requestErrors['date_from'] = 'Empty date!';
} else {
    try {
        $dateFrom = RequestParamProcessor::getLocalDate($us_dateFrom);
    } catch (InvalidArgumentException $e) {
        $requestErrors['date_from'] = 'Invalid date [' . $us_dateFrom . ']';
    }
}

$us_dateTo = $requests['date_to'];
$dateTo = null;
if(!isset($us_dateTo)) {
    $requestErrors['date_to'] = 'Empty date!';
} else {
    try {
        $dateTo = RequestParamProcessor::getLocalDate($us_dateTo);
    } catch (InvalidArgumentException $e) {
        $requestErrors['date_to'] = 'Invalid date [' . $us_dateTo . ']';
    }
}

$validStatus = array('all', PalletDowngrade::STATUS_APPROVED, PalletDowngrade::STATUS_CANCELLED, PalletDowngrade::STATUS_OPEN, PalletDowngrade::STATUS_REJECTED);
$status = $requests['status'];
if (!in_array($status, $validStatus)) {
    $requestErrors['status'] = "Unknown status [$status]!";
}

$validTypes = array('all', PalletDowngrade::TYPE_EXP_TO_ECO, PalletDowngrade::TYPE_ECO_TO_KW4, PalletDowngrade::TYPE_EXP_TO_KW4);
$type = $requests['type'];
if (!in_array($type, $validTypes)) {
    $requestErrors['type'] = "Unknown type [$type]!";
}
$reason = trim($requests['reason']);
if (empty($reason)) {
    $reason = 'all';
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
    $params[] = $dateFrom->format('Y-m-d');
    $params[] = $dateTo->format('Y-m-d');

    $optionalParamNo = 4;
    $statusFilter = '1=1';
    if ($status !== 'all') {
        $statusFilter = "status = $$optionalParamNo";
        $optionalParamNo++;
        $params[] = $status;
    }

    $typeFilter = '1=1';
    if ($type !== 'all') {
        $typeFilter = "jenis_downgrade = $$optionalParamNo";
        $optionalParamNo++;
        $params[] = $type;
    }

    $reasonFilter = '1=1';
    if (!empty($motifName)) {
        $reasonFilter = "keterangan = $$optionalParamNo";
        $optionalParamNo++;
        $params[] = $motifName;
    }

    $db = PostgresqlDatabase::getInstance();
    $query = "
        SELECT DISTINCT ON (no_downgrade)
               subplant,
               no_downgrade    AS downgrade_id,
               tanggal         AS request_date,
               create_date     AS created_at,
               create_user     AS created_by,
               status,
               jenis_downgrade AS type,
               keterangan      AS reason,                                
               COUNT(*) OVER w AS pallet_count,
               SUM(qty) OVER w AS total_pallet_quantity,
               FIRST_VALUE(last_updated_at) OVER w AS last_updated_at,
               FIRST_VALUE(last_updated_by) OVER w AS last_updated_by,
               FIRST_VALUE(approval_user) OVER w AS approved_by,
               FIRST_VALUE(date_approval) OVER w AS approved_at
        FROM tbl_sp_downgrade_pallet
        WHERE subplant = ANY($1)
          AND tanggal BETWEEN $2 AND $3
          AND $statusFilter
          AND $reasonFilter
        WINDOW w AS (PARTITION BY no_downgrade ORDER BY last_updated_at DESC, subplant, no_downgrade);
    ";
    $res = $db->parameterizedQuery($query, $params);

    $response = array();
    while ($queryResult = pg_fetch_assoc($res)) {
        $queryResult = QueryResultConverter::toInt($queryResult, array('pallet_count', 'total_pallet_quantity'));
        $response[] = $queryResult;
    }

    $db->close();
    HttpUtils::sendJsonResponse($response, $user->gua_kode);
} catch (PostgresqlDatabaseException $e) {
    HttpUtils::sendError('json', (string) $e,
        Env::isDebug() ? $e->getTrace() : array(),
        $e->isRaisedManually() ? HttpUtils::HTTP_RESPONSE_UNPROCESSABLE_ENTITY : HttpUtils::HTTP_RESPONSE_SERVER_ERROR
    );
}
