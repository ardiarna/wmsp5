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
    $allowedRoles = RoleAcl::downgradePallets();
    $authorized = UserRole::hasAnyRole($allowedRoles);
}

if (!$authorized) {
    $errorMessage = 'You are not authorized to access downgrade data!';
    HttpUtils::sendError('json', $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}

$requests = HttpUtils::getRequestValues(array('id'));
$requestErrors = array();

// validate request
$downgradeId = $requests['id'];
if (empty($downgradeId)) {
    $requestErrors['id'] = 'id is empty!';
}

if (!empty($requestErrors)) {
    HttpUtils::sendError('json', 'Kesalahan pada permintaan data!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();
    $params = array($downgradeId);
    $query = "
        SELECT dwg.pallet_no,
               dwg.subplant,
               hasilbj.tanggal                            AS production_date,
               hasilbj.line                               AS line,
               hasilbj.regu                               AS creator_group,
               hasilbj.shift                              AS creator_shift,
               hasilbj.size                               AS size,
               hasilbj.shade                              AS shading,
               dwg.qty                                    AS quantity,
               dwg.item_kode_lama                         AS current_motif_id,
               t1.item_nama                               AS current_motif_name,
               (CASE WHEN dwg.status IN ('O', 'A') THEN COALESCE(dwg.item_kode_baru, t2.item_kode) 
                    ELSE NULL END) AS new_motif_id,
               (CASE WHEN dwg.status IN ('O', 'A') THEN t2.item_nama ELSE NULL END) AS new_motif_name
        FROM tbl_sp_downgrade_pallet dwg
        LEFT JOIN tbl_sp_hasilbj hasilbj ON dwg.plan_kode = hasilbj.plan_kode AND dwg.pallet_no = hasilbj.pallet_no
        JOIN item t1 ON dwg.item_kode_lama = t1.item_kode
        LEFT JOIN (
            SELECT *, ROW_NUMBER() OVER (PARTITION BY category_kode, color, quality, plant_kode ORDER BY category_kode) row_num
            FROM item
        ) t2 ON (
            CASE
                WHEN dwg.item_kode_baru IS NOT NULL THEN (dwg.item_kode_baru = t2.item_kode AND row_num = 1)
                ELSE (
                        t2.quality = (
                            CASE
                                WHEN dwg.jenis_downgrade = const_downgrade_type_exp_to_eco() THEN 'EKONOMI'
                                WHEN dwg.jenis_downgrade IN
                                     (const_downgrade_type_exp_to_kw4(), const_downgrade_type_eco_to_kw4()) THEN 'KW4'
                                END) 
                        AND t1.category_kode = t2.category_kode
                        AND t1.color = t2.color
                        AND dwg.plan_kode = t2.plant_kode::VARCHAR
                        AND row_num = 1) 
                END
        )
        WHERE no_downgrade = $1
        ORDER BY pallet_no;
    ";
    $res = $db->parameterizedQuery($query, $params);

    $response = array();
    while ($queryResult = pg_fetch_assoc($res)) {
        $queryResult['quantity'] = isset($queryResult['quantity']) ? intval($queryResult['quantity']) : null;
        $queryResult['line'] = isset($queryResult['line']) ? intval($queryResult['line']) : null;
        $queryResult['creator_shift'] = isset($queryResult['creator_shift']) ? intval($queryResult['creator_shift']) : null;

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
