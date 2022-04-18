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
$requests = HttpUtils::getRequestValues(array('subplant', 'customer', 'order_target_date', 'keterangan', 'pallet_no_s', 'qty_s'));
$requestErrors = array();

$subplant = trim($requests['subplant']);
if (empty($subplant)) {
    $requestErrors['subplant'] = 'subplant is empty!';
}

$customer = trim($requests['customer']);
if (empty($customer)) {
    $requestErrors['customer'] = 'customer is empty!';
}

$order_target_date = trim($requests['order_target_date']);
if (empty($order_target_date)) {
    $requestErrors['order_target_date'] = 'target date is empty!';
}

$pallet_no_s = $requests['pallet_no_s'];
if (!is_array($pallet_no_s)) {
    $requestErrors['pallet_no_s'] = 'motif id must be an array!';
}

$qty_s = $requests['qty_s'];
if (!is_array($qty_s)) {
    $requestErrors['qty_s'] = 'quantity must be an array!';
}

if (!empty($requestErrors)) {
    HttpUtils::sendError('json', 'Kesalahan pada permintaan data!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

$keterangan = $requests['keterangan'];
$plant = substr($subplant, 0, 1);

try {
    $db = PostgresqlDatabase::getInstance();

    $userid = $user->gua_kode;
    $query = 'SELECT create_block_quantity_request($1, $2, $3, $4, $5, $6, $7, $8, $9)';
    $params = array('ADD', $plant, $subplant, $customer, $order_target_date, $keterangan, $pallet_no_s, $qty_s, $userid);
    $cursor = $db->parameterizedQuery($query, $params);

    $result = pg_fetch_row($cursor, null, PGSQL_NUM);
    $order_id = $result[0];

    $queryCreatedRecord = 'SELECT a.subplant, a.order_id, a.customer_id, a.order_target_date, a.order_status, a.keterangan, a.create_user, a.create_date, a.last_updated_at, a.last_updated_by, a.order_target_date as order_target_date_v
        FROM tbl_gbj_stockblock a 
        WHERE order_id = $1';
    $cursorUpdatedRecord = $db->parameterizedQuery($queryCreatedRecord, array($order_id));
    assert(pg_num_rows($cursorUpdatedRecord) === 1);
    $rview = pg_fetch_assoc($cursorUpdatedRecord);

    $response = $rview;
    $db->close();
    HttpUtils::sendJsonResponse($response, $user->gua_kode);
} catch (PostgresqlDatabaseException $e) {
    HttpUtils::sendError('json', (string)$e,
        Env::isDebug() ? $e->getTrace() : array(),
        $e->isRaisedManually() ? HttpUtils::HTTP_RESPONSE_UNPROCESSABLE_ENTITY : HttpUtils::HTTP_RESPONSE_SERVER_ERROR
    );
}
