<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

use Model\PalletDowngrade;
use Security\RoleAcl;
use Utils\Env;

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
    $allowedRoles = RoleAcl::downgradePalletsModification();
    $authorized = UserRole::hasAnyRole($allowedRoles);
}

if (!$authorized) {
    $errorMessage = 'You are not authorized to modify downgrade data!';
    HttpUtils::sendError('json', $errorMessage, array(), HttpUtils::HTTP_RESPONSE_FORBIDDEN);
    exit;
}

// get & validate requests
$requests = HttpUtils::getRequestValues(array('pallet_nos', 'subplant', 'type', 'reason'));
$requestErrors = array();

$requestedSubplant = $requests['subplant'];

$palletNos = $requests['pallet_nos'];
if (!is_array($palletNos)) {
    $requestErrors['pallet_nos'] = 'pallet_nos must be an array!';
}

$type = trim($requests['type']);
if (empty($type)) {
    $requestErrors['type'] = 'type is empty!';
} elseif (!in_array($type, array_keys(PalletDowngrade::availableTypes()))) {
    $requestErrors['type'] = "Unknown type $type!";
}

$reason = trim($requests['reason']);
if (empty($reason)) {
    $requestErrors['reason'] = 'reason is empty!';
}

if (!empty($requestErrors)) {
    HttpUtils::sendError('json', 'Kesalahan pada permintaan data!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();

    $userid = $user->gua_kode;
    $query = 'SELECT create_downgrade_request($1, $2, $3, $4, $5)';
    $params = array($requestedSubplant, $palletNos, $type, $reason, $userid);
    $cursor = $db->parameterizedQuery($query, $params);

    $result = pg_fetch_row($cursor, null, PGSQL_NUM);
    $downgradeId = $result[0];

    $queryCreatedRecord = '
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
        WHERE no_downgrade = $1
        WINDOW w AS (PARTITION BY no_downgrade ORDER BY last_updated_at DESC, subplant, no_downgrade)';
    $cursorUpdatedRecord = $db->parameterizedQuery($queryCreatedRecord, array($downgradeId));
    assert(pg_num_rows($cursorUpdatedRecord) === 1);
    $downgradeRequest = pg_fetch_assoc($cursorUpdatedRecord);
    $downgradeRequest['pallet_quantity'] = intval($downgradeRequest['pallet_quantity']);
    $downgradeRequest['total_pallet_quantity'] = intval($downgradeRequest['total_pallet_quantity']);

    $response = $downgradeRequest;
    $db->close();
    HttpUtils::sendJsonResponse($response, $user->gua_kode);
} catch (PostgresqlDatabaseException $e) {
    // HttpUtils::sendError('json', (string)$e,
    //     Env::isDebug() ? $e->getTrace() : array(),
    //     $e->isRaisedManually() ? HttpUtils::HTTP_RESPONSE_UNPROCESSABLE_ENTITY : HttpUtils::HTTP_RESPONSE_SERVER_ERROR
    // );
    HttpUtils::sendError('json', (string)$e, array($requestedSubplant, $palletNos, $type, $reason, $userid),
        $e->isRaisedManually() ? HttpUtils::HTTP_RESPONSE_UNPROCESSABLE_ENTITY : HttpUtils::HTTP_RESPONSE_SERVER_ERROR
    );
}
