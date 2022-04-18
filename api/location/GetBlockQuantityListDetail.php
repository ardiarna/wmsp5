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
$requests = HttpUtils::getRequestValues(array('customer'));
$requestErrors = array();

$customer_id = trim($requests['customer']);
if (empty($customer_id)) {
    $requestErrors['customer'] = 'customer is empty!';
}

if (!empty($requestErrors)) {
    HttpUtils::sendError('json', 'Kesalahan pada permintaan data!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();

    $query = "SELECT item_gbj_stockblock.pallet_no, item.item_kode AS motif_id, item.item_nama AS motif_name, tbl_sp_hasilbj.size, tbl_sp_hasilbj.shade AS shading, item_gbj_stockblock.quantity as qty_block, item_gbj_stockblock.order_id, 
        CASE
            WHEN item.quality = 'EXPORT' THEN 'EXP'
            WHEN item.quality = 'ECONOMY' OR item.quality = 'EKONOMI' THEN 'ECO'
            ELSE item.quality
        END AS quality
        FROM item_gbj_stockblock
        JOIN tbl_gbj_stockblock ON (item_gbj_stockblock.order_id = tbl_gbj_stockblock.order_id)
        JOIN tbl_sp_hasilbj ON (item_gbj_stockblock.pallet_no = tbl_sp_hasilbj.pallet_no)
        JOIN item ON (tbl_sp_hasilbj.item_kode = item.item_kode)
        WHERE item_gbj_stockblock.order_status = 'O'
        AND tbl_gbj_stockblock.customer_id = $1
        ORDER BY item_gbj_stockblock.pallet_no";
    $params = array($customer_id);    
    $res = $db->parameterizedQuery($query, $params);

    $response = array();
    while ($queryResult = pg_fetch_assoc($res)) {
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
