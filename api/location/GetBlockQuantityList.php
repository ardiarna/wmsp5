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

$requests = HttpUtils::getRequestValues(array('subplant', 'tiperpt'));
$requestErrors = array();

// validate request
$subplant = trim($requests['subplant']);
$tiperpt = trim($requests['tiperpt']);
if (empty($subplant)) {
    $requestErrors['subplant'] = 'subplant kosong!';
} elseif (!RequestParamProcessor::validateSubplantId($subplant) && $subplant !== 'all') {
    $requestErrors['subplant'] = "subplant $subplant tidak dikenal!";
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

    if($tiperpt == 'C') {
        $query = "SELECT customer_id as idnya, customer_id, SUM(quantity) AS qty_block, string_agg(order_id||'('||pallet_no||':'||quantity||')', ', ') AS order_id 
        FROM (
            SELECT tbl_gbj_stockblock.customer_id, item_gbj_stockblock.quantity, item_gbj_stockblock.order_id, item_gbj_stockblock.pallet_no
            FROM item_gbj_stockblock
            JOIN tbl_gbj_stockblock ON (item_gbj_stockblock.order_id = tbl_gbj_stockblock.order_id)
            JOIN tbl_sp_hasilbj ON (item_gbj_stockblock.pallet_no = tbl_sp_hasilbj.pallet_no)
            JOIN item ON (tbl_sp_hasilbj.item_kode = item.item_kode)
            WHERE item_gbj_stockblock.order_status = 'O'
            AND item_gbj_stockblock.subplant = ANY($1)
        ) AS z
        GROUP BY customer_id
        ORDER BY customer_id";        
    } else {
        $query = "SELECT c.*, (COALESCE(a.qty_wh,0)-COALESCE(c.qty_block,0)) AS qty_ava
        FROM (
            SELECT subplant||motif_id||quality||size||shading as idnya, subplant, motif_id, motif_name, quality, size, shading, SUM(quantity) AS qty_block, string_agg(order_id||'('||pallet_no||':'||quantity||')', ', ') AS order_id 
            FROM (
                SELECT item_gbj_stockblock.subplant, item.item_kode AS motif_id, item.item_nama AS motif_name, tbl_sp_hasilbj.size, tbl_sp_hasilbj.shade AS shading, item_gbj_stockblock.quantity, item_gbj_stockblock.order_id, item_gbj_stockblock.pallet_no, 
                CASE
                    WHEN item.quality = 'EXPORT' THEN 'EXP'
                    WHEN item.quality = 'ECONOMY' OR item.quality = 'EKONOMI' THEN 'ECO'
                    ELSE item.quality
                END AS quality
                FROM item_gbj_stockblock
                JOIN tbl_sp_hasilbj ON (tbl_sp_hasilbj.pallet_no = item_gbj_stockblock.pallet_no)
                JOIN item ON (tbl_sp_hasilbj.item_kode = item.item_kode)
                WHERE item_gbj_stockblock.order_status = 'O'
            ) AS z
            GROUP BY subplant, motif_id, motif_name, quality, size, shading
        ) AS c 
        LEFT JOIN (
            SELECT production_subplant, motif_id, motif_name, quality, size, shading, SUM(total_quantity) AS qty_wh
            FROM summary_stock_by_motif_location
            GROUP BY production_subplant, motif_id, motif_name, quality, size, shading 
        ) AS a ON(c.subplant = a.production_subplant AND c.motif_id = a.motif_id AND c.motif_name = a.motif_name AND c.quality = a.quality AND c.size = a.size AND c.shading = a.shading)
        WHERE c.subplant = ANY($1)
        ORDER BY c.subplant, c.motif_name, c.quality, c.size, c.shading";
    }
    
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
