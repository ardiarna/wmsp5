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
    $allowedRoles = RoleAcl::blockQuantity();
    $authorized = UserRole::hasAnyRole($allowedRoles);
}

if (!$authorized) {
    $errorMessage = 'You are not authorized to access block quantity data!';
    HttpUtils::sendError($mode, $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}

$requests = HttpUtils::getRequestValues(array('subplant', 'date_from', 'date_to', 'status'));
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

$status = $requests['status'];

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
        $statusFilter = "a.order_status = $$optionalParamNo";
        $optionalParamNo++;
        $params[] = $status;
    }

    $db = PostgresqlDatabase::getInstance();

    //====Update Block quantity yang sudah dikirim/dikeluarkan dari warehouse=============================================
    $query2 = 'UPDATE item_gbj_stockblock SET order_status = $1 
               WHERE pallet_no IN (
                    select pallet_no from tbl_sp_hasilbj where pallet_no in (
                        SELECT pallet_no FROM item_gbj_stockblock WHERE order_status = $2
                    ) and last_qty = 0
                )';
    $params2 = array('S', 'O');
    $cursor2 = $db->parameterizedQuery($query2, $params2);

    // $query4 = 'UPDATE item_gbj_stockblock SET quantity = tbl_sp_hasilbj.last_qty 
    //     FROM tbl_sp_hasilbj 
    //     WHERE item_gbj_stockblock.pallet_no = tbl_sp_hasilbj.pallet_no 
    //     AND item_gbj_stockblock.order_status = $1 AND item_gbj_stockblock.quantity <> tbl_sp_hasilbj.last_qty';
    // $params4 = array('O');
    // $cursor4 = $db->parameterizedQuery($query4, $params4);

    $query3 = 'UPDATE tbl_gbj_stockblock SET order_status = $1
               WHERE order_id IN (
                    SELECT a.order_id
                    FROM (
                        SELECT order_id, count(pallet_no) AS jml_a FROM item_gbj_stockblock GROUP BY order_id
                    ) AS a
                    LEFT JOIN (
                        SELECT order_id, count(pallet_no) AS jml_s FROM item_gbj_stockblock WHERE order_status = $2 GROUP BY order_id
                    ) AS b ON (a.order_id = b.order_id)
                    WHERE a.jml_a = b.jml_s
               ) AND order_status = $3';
    $params3 = array('S', 'S', 'O');
    $cursor3 = $db->parameterizedQuery($query3, $params3);
    //=================================================

    $query = "SELECT a.subplant, a.order_id, a.customer_id, a.order_target_date, a.order_status, a.keterangan, a.create_user, a.create_date, a.last_updated_at, a.last_updated_by, a.order_target_date as order_target_date_v
        FROM tbl_gbj_stockblock a 
        WHERE a.subplant = ANY($1)
        AND a.order_target_date BETWEEN $2 AND $3
        AND $statusFilter
        ORDER BY a.subplant, a.order_id";
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
