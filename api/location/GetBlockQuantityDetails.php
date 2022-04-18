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
    $errorMessage = 'You are not authorized to access block quantity data!';
    HttpUtils::sendError($mode, $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}

$requests = HttpUtils::getRequestValues(array('id'));
$requestErrors = array();

// validate request
$orderId = $requests['id'];
if (empty($orderId)) {
    $requestErrors['id'] = 'id is empty!';
}

if (!empty($requestErrors)) {
    HttpUtils::sendError('json', 'Kesalahan pada permintaan data!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();
    $params = array($orderId);
     $query0 = "SELECT item.item_nama, tbl_sp_hasilbj.size, tbl_sp_hasilbj.shade, SUM(c.quantity) AS qty 
        FROM item_gbj_stockblock c
        JOIN tbl_sp_hasilbj ON (tbl_sp_hasilbj.pallet_no = c.pallet_no)
        JOIN item ON (tbl_sp_hasilbj.item_kode = item.item_kode)
        WHERE c.order_id = $1
        GROUP BY item.item_nama, tbl_sp_hasilbj.size, tbl_sp_hasilbj.shade";

    $res0 = $db->parameterizedQuery($query0, $params);
    $isrimpil = array();
    while ($r0 = pg_fetch_assoc($res0)) {
        if($r0['qty'] < 100) {
            $isrimpil[$r0['item_nama']][$r0['size']][$r0['shade']] = 'YA';   
        } else {
            $isrimpil[$r0['item_nama']][$r0['size']][$r0['shade']] = 'TIDAK';
        }
        
    }
    $query = "SELECT c.subplant, c.pallet_no, c.order_status, item.item_nama AS motif_name, tbl_sp_hasilbj.size, tbl_sp_hasilbj.shade AS shading, c.quantity AS qty, 
        CASE
            WHEN item.quality = 'EXPORT' THEN 'EXP'
            WHEN item.quality = 'ECONOMY' OR item.quality = 'EKONOMI' THEN 'ECO'
            ELSE item.quality
        END AS quality
        FROM item_gbj_stockblock c
        JOIN tbl_sp_hasilbj ON (tbl_sp_hasilbj.pallet_no = c.pallet_no)
        JOIN item ON (tbl_sp_hasilbj.item_kode = item.item_kode)
        WHERE c.order_id = $1
        ORDER BY c.pallet_no;";

    $res = $db->parameterizedQuery($query, $params);

    $response = array();
    while ($queryResult = pg_fetch_assoc($res)) {
        $queryResult['qty'] = isset($queryResult['qty']) ? intval($queryResult['qty']) : null;
        $queryResult['isrimpil'] = $isrimpil[$queryResult['motif_name']][$queryResult['size']][$queryResult['shading']];
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
