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
    $allowedRoles = RoleAcl::blockQuantity();
    $authorized = UserRole::hasAnyRole($allowedRoles);
}

if (!$authorized) {
    $errorMessage = 'You are not authorized to modify block quantity data!';
    HttpUtils::sendError($mode, $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}

// get & validate requests
$requests = HttpUtils::getRequestValues(array('id', 'reason'));
$requestErrors = array();

$orderId = trim($requests['id']);
if (empty($orderId)) {
    $requestErrors['id'] = 'id is empty!';
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

    $v_reason = ' [Batal: '.$reason.']';
    $userid = $user->gua_kode;
    $query = 'UPDATE tbl_gbj_stockblock SET order_status = $2, keterangan = keterangan || $3, last_updated_at = $4, last_updated_by = $5 WHERE order_id = $1;';
    $params = array($orderId, 'C', $v_reason, 'now()', $userid);
    $cursor = $db->parameterizedQuery($query, $params);

    $query2 = 'UPDATE item_gbj_stockblock SET order_status = $2 WHERE order_id = $1;';
    $params2 = array($orderId, 'C');
    $cursor2 = $db->parameterizedQuery($query2, $params2);

    $queryUpdatedRecord = 'SELECT a.subplant, a.order_id, a.customer_id, a.order_target_date, a.order_status, a.keterangan, a.create_user, a.create_date, a.last_updated_at, a.last_updated_by, a.order_target_date as order_target_date_v
        FROM tbl_gbj_stockblock a 
        WHERE order_id = $1';
    $cursorUpdatedRecord = $db->parameterizedQuery($queryUpdatedRecord, array($orderId));
    assert(pg_num_rows($cursorUpdatedRecord) === 1);
    $rview = pg_fetch_assoc($cursorUpdatedRecord);

    $response = $rview;
    $db->close();
    HttpUtils::sendJsonResponse($response, $user->gua_kode);
} catch (PostgresqlDatabaseException $e) {
    HttpUtils::sendError('json', (string) $e,
        Env::isDebug() ? $e->getTrace() : array(),
        $e->isRaisedManually() ? HttpUtils::HTTP_RESPONSE_UNPROCESSABLE_ENTITY : HttpUtils::HTTP_RESPONSE_SERVER_ERROR
    );
}
