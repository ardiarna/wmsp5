<?php

use Security\RoleAcl;

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

SessionUtils::sessionStart();

if (!SessionUtils::isAuthenticated()) {
    HttpUtils::sendError('json', 'Belum terautentikasi!', array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}

// check authorization.
$authorized = !empty($user->gua_subplants);
if ($authorized) {
    // check role
    // for now only allow kabag and above to see the data.
    $allowedRoles = RoleAcl::palletsWithoutLocation();
    $authorized = UserRole::hasAnyRole($allowedRoles);
}

if (!$authorized) {
    $errorMessage = 'You are not authorized to access Pallets without Location data!';
    HttpUtils::sendError($mode, $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}

$userid = SessionUtils::getUser()->gua_kode;
$requests = HttpUtils::getRequestValues(array('subplant'), 'all');

$requestErrors = array();
$subplant = $requests['subplant'];
if (!RequestParamProcessor::validateSubplantId($subplant) && $subplant !== 'all') {
    $requestErrors['subplant'] = "Subplant $subplant tidak diketahui!";
}

if (!empty($requestErrors)) {
    HttpUtils::sendError('json', 'Request error!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();

    $querySubplant = $subplant === 'all' ? '' : 'AND subplant = ANY($1)';
    $query = "
SELECT pallet_no,
       subplant,
       tanggal        AS created_at,
       item.item_kode AS motif_id,
       item.item_nama AS motif_name,
       category_nama  AS motif_dimension,
       item.quality   AS quality,
       line,
       regu           AS creator_group,
       shift          AS creator_shift,
       shade          AS shading,
       size,
       qty            AS initial_quantity,
       last_qty       AS current_quantity
FROM tbl_sp_hasilbj
JOIN item ON tbl_sp_hasilbj.item_kode = item.item_kode
JOIN category cat ON LEFT(item.item_kode, 2) = cat.category_kode
WHERE pallet_no NOT IN (SELECT io_no_pallet FROM inv_opname)
  AND last_qty>0 
  AND status_plt='R'
  $querySubplant
ORDER BY tanggal,quality,pallet_no,item.item_nama";
    $params = array();
    if ($subplant !== 'all') {
        if ($subplant === '4A' || $subplant === '5A') {
            $params[] = array($subplant, $subplant[0]);
        } else {
            $params[] = array($subplant);
        }
    }
    $cursor = $db->parameterizedQuery($query, $params);
    $results = array();
    while ($row = pg_fetch_assoc($cursor)) {
        $row['initial_quantity'] = intval($row['initial_quantity']);
        $row['current_quantity'] = intval($row['current_quantity']);
        $row['line'] = isset($row['line']) ? intval($row['line']) : '-';
        $row['creator_shift'] = isset($row['creator_shift']) ? intval($row['creator_shift']) : '-';
        $row['creator_group'] = isset($row['creator_group']) ? $row['creator_group'] : '-';
        if($row['quality'] == "KW4") {
            $row['quality'] =  "LOKAL";
            $row['motif_name'] = str_replace("KW4","LOKAL",$row['motif_name']);
        } else if($row['quality'] == "KW5") {
            $row['quality'] =  "BBM SQUARING";
            $row['motif_name'] = str_replace("KW5","BBM SQUARING",$row['motif_name']);
        } else if($row['quality'] == "KW6") {
            $row['quality'] =  "BBM OVERSIZE";
            $row['motif_name'] = str_replace("KW6","BBM OVERSIZE",$row['motif_name']);
        }
        $results[] = $row;
    }
    $db->close();
    HttpUtils::sendJsonResponse($results, '', $userid);
} catch (PostgresqlDatabaseException $e) {
    $errorMessage = $e->getMessage();
    $additionalInfo = array(
        'query' => $e->getQuery(),
        'db_message' => $e->getOriginalMessage()
    );
    HttpUtils::sendError($mode, $errorMessage, $additionalInfo);
}
