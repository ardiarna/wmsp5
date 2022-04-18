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

$requests = HttpUtils::getRequestValues(array('locationsubplant', 'dimension', 'isrimpil', 'motifname', 'size', 'shading', 'quality'));
$requestErrors = array();

// validate request
$locationsubplant = trim($requests['locationsubplant']);
$dimension = trim($requests['dimension']);
$isrimpil = trim($requests['isrimpil']);
$motif_name = trim($requests['motifname']);
$size = trim($requests['size']);
$shading = trim($requests['shading']);
$quality = trim($requests['quality']);

try {
    $params = array();
    $params[] = $locationsubplant;
    $params[] = $dimension;
    $params[] = $isrimpil;
    $params[] = $motif_name;
    $params[] = $size;
    $params[] = $shading;
    $params[] = $quality;
    
    $db = PostgresqlDatabase::getInstance();
    $row = array();
    $response = array();

    $query = "SELECT a.motif_name, a.size, a.shading, a.quality, 
            a.pallet_month_category, a.current_quantity, a.location_subplant, a.location_area_name, 
            a.location_area_no, a.location_line_no, a.location_id, a.pallet_no, a.production_subplant, a.motif_id, 
            a.motif_dimension, a.creation_date, a.creator_group, a.creator_shift, a.line, a.pallet_age, 
            a.pallet_age_category, a.is_rimpil, a.is_blocked, a.pallet_status
        FROM pallets_with_location_age_and_rimpil a 
        WHERE a.current_quantity > 0
        AND a.location_subplant = $1 
        AND a.motif_dimension = $2
        AND a.is_rimpil = $3
        AND a.motif_name = $4
        AND a.size = $5
        AND a.shading = $6
        AND a.quality = $7
        ORDER BY a.location_area_name, a.location_area_no, a.location_line_no, a.location_id, a.pallet_no";
    $res = $db->parameterizedQuery($query, $params);
    while ($r = pg_fetch_assoc($res)) {
        $response[] = $r;
    }
    $db->close();
    HttpUtils::sendJsonResponse($response, $query3);
} catch (PostgresqlDatabaseException $e) {
    HttpUtils::sendError('json', (string) $e,
        Env::isDebug() ? $e->getTrace() : array(),
        $e->isRaisedManually() ? HttpUtils::HTTP_RESPONSE_UNPROCESSABLE_ENTITY : HttpUtils::HTTP_RESPONSE_SERVER_ERROR
    );
}
