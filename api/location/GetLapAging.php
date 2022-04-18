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

$requests = HttpUtils::getRequestValues(array('locationsubplant', 'dimension', 'isrimpil'));
$requestErrors = array();

// validate request
$locationsubplant = trim($requests['locationsubplant']);
$dimension = trim($requests['dimension']);
$isrimpil = trim($requests['isrimpil']);

try {
    $params = array();
    $params[] = $locationsubplant;
    $params[] = $dimension;
    $params[] = $isrimpil;
    
    $db = PostgresqlDatabase::getInstance();
    $row = array();
    $response = array();
    $arr_quality = array('01' => 'EXP', '02' => 'ECO', '03' => 'LOCAL', '04' => 'KW4', '05' => 'KW5', '06' => 'KW6');

    $query = "SELECT a.motif_name, a.size, a.shading, a.quality, lower(substring(a.pallet_month_category from 1 for 1)) AS kategori, 
            a.pallet_month_category, a.current_quantity, a.location_subplant, a.location_area_name, 
            a.location_area_no, a.location_line_no, a.location_id, a.pallet_no, a.production_subplant, a.motif_id, 
            a.motif_dimension, a.creation_date, a.creator_group, a.creator_shift, a.line, a.pallet_age, 
            a.pallet_age_category, a.is_rimpil, a.is_blocked, a.pallet_status
        FROM pallets_with_location_age_and_rimpil a 
        WHERE a.current_quantity > 0
        AND a.location_subplant = $1 
        AND a.motif_dimension = $2
        AND a.is_rimpil = $3
        ORDER BY a.motif_name, a.size, a.shading, a.quality, substring(a.pallet_month_category from 1 for 1)";
    $res = $db->parameterizedQuery($query, $params);
    while ($r = pg_fetch_assoc($res)) {
        $arr_nilai["$r[motif_name]"]["$r[size]"]["$r[shading]"]["$r[quality]"]["$r[kategori]"] += $r[current_quantity];
        $arr_total["$r[motif_name]"]["$r[size]"]["$r[shading]"]["$r[quality]"] += $r[current_quantity];
    }
    
    

    ksort($arr_nilai);
    reset($arr_nilai);
    foreach ($arr_nilai as $motif_name => $a_size) {
        foreach ($a_size as $size => $a_shading) {
            foreach ($a_shading as $shading => $a_quality) {
                foreach ($a_quality as $quality => $a_kategori) {
                    $response[] = array(
                        'motif_name' => $motif_name,
                        'size' => $size,
                        'shading' => $shading,
                        'quality' => $quality,
                        'a' => $a_kategori['a'],
                        'b' => $a_kategori['b'],
                        'c' => $a_kategori['c'],
                        'd' => $a_kategori['d'],
                        'e' => $a_kategori['e'],
                        'f' => $a_kategori['f'],
                        'g' => $a_kategori['g'],
                        'h' => $a_kategori['h'],
                        'i' => $a_kategori['i'],
                        'total' => $arr_total[$motif_name][$size][$shading][$quality],
                    );
                }
            }
        }
    }
    $db->close();
    HttpUtils::sendJsonResponse($response, $query3);
} catch (PostgresqlDatabaseException $e) {
    HttpUtils::sendError('json', (string) $e,
        Env::isDebug() ? $e->getTrace() : array(),
        $e->isRaisedManually() ? HttpUtils::HTTP_RESPONSE_UNPROCESSABLE_ENTITY : HttpUtils::HTTP_RESPONSE_SERVER_ERROR
    );
}
