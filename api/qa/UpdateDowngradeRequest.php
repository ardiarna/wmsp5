<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

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
    HttpUtils::sendError('json', $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}

// get & validate requests
$requests = HttpUtils::getRequestValues(array('id', 'reason', 'pallets_to_add', 'pallets_to_remove'));
$requestErrors = array();

$downgradeId = trim($requests['id']);
if (empty($downgradeId)) {
    $requestErrors['id'] = 'id is empty!';
}
$reason = trim($requests['reason']);
if (empty($reason)) {
    $requestErrors['reason'] = 'reason is empty!';
}

$palletsToAdd = $requests['pallets_to_add'];
if (empty($palletsToAdd)) {
    $palletsToAdd = array();
}
$palletsToRemove = $requests['pallets_to_remove'];
if (empty($palletsToRemove)) {
    $palletsToRemove = array();
}
if (!is_array($palletsToAdd)) {
    $errorMessage = 'pallets_to_add must be an array!';
    $requestErrors['pallets_to_add'] = $errorMessage;
}

if (!is_array($palletsToRemove)) {
    $errorMessage = 'pallets_to_remove must be an array!';
    $requestErrors['pallets_to_remove'] = $errorMessage;
}

if (!empty($requestErrors)) {
    HttpUtils::sendError('json', 'Kesalahan pada permintaan data!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();

    $userid = $user->gua_kode;
    $query = 'SELECT update_downgrade_request($1, $2)';
    $request = json_encode(array(
        'downgrade_id' => $downgradeId,
        'add' => $palletsToAdd,
        'del' => $palletsToRemove,
        'reason' => $reason
    ));
    $params = array($request, $userid);
    $cursor = $db->parameterizedQuery($query, $params);

    $queryUpdatedRecord = '
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
    $cursorUpdatedRecord = $db->parameterizedQuery($queryUpdatedRecord, array($downgradeId));
    assert(pg_num_rows($cursorUpdatedRecord) === 1);
    $updatedDowngradeRequest = pg_fetch_assoc($cursorUpdatedRecord);
    $updatedDowngradeRequest['pallet_count'] = intval($updatedDowngradeRequest['pallet_count']);
    $updatedDowngradeRequest['total_pallet_quantity'] = intval($updatedDowngradeRequest['total_pallet_quantity']);

    $response = $updatedDowngradeRequest;
    $db->close();
    HttpUtils::sendJsonResponse($response, $user->gua_kode);
} catch (PostgresqlDatabaseException $e) {
    HttpUtils::sendError('json', (string) $e,
        Env::isDebug() ? $e->getTrace() : array(),
        $e->isRaisedManually() ? HttpUtils::HTTP_RESPONSE_UNPROCESSABLE_ENTITY : HttpUtils::HTTP_RESPONSE_SERVER_ERROR
    );
}
