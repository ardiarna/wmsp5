<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

SessionUtils::sessionStart();
$requestParams = HttpUtils::getRequestValues(array('mode'), 'xml');
if (!HttpUtils::isValidMode($mode)) {
    HttpUtils::sendError($mode, '');
}

if (!SessionUtils::isAuthenticated()) {
    // print error
    $errorMessage = 'You are not authenticated!';
    HttpUtils::sendError($mode, $errorMessage);
    exit;
}
$user = SessionUtils::getUser();

try {
    $db = PostgresqlDatabase::getInstance();

    $query = 'SELECT 
  inv_master_area.plan_kode AS subplant, 
  inv_master_area.kd_area AS area_code, 
  inv_master_area.ket_area AS area_name,
  inv_master_area.kd_baris AS line_count,
  inv_master_area.area_status AS is_active
FROM inv_master_area
WHERE inv_master_area.plan_kode = ANY($1)';
    $subplants = $user->gua_subplants;
    $cursor = $db->parameterizedQuery($query, array($subplants));

    $locations = array();
    while ($row = pg_fetch_assoc($cursor)) {
        $row['line_count'] = intval($row['line_count']);
        $row['is_active'] = $row['is_active'] === PostgresqlDatabase::PGSQL_TRUE;
        $locations[] = $row;
    }

    switch($mode) {
        case HttpUtils::MODE_XML:
            HttpUtils::sendXmlResponse($locations);
            break;
        case HttpUtils::MODE_JSON:
            HttpUtils::sendJsonResponse($locations, '', $user->gua_kode);
            break;
    }
} catch (PostgresqlDatabaseException $e) {
    $errorMessage = $e->getMessage();
    $additionalInfo = array(
        'query' => $e->getQuery(),
        'db_message' => $e->getOriginalMessage()
    );
    HttpUtils::sendError($mode, $errorMessage, $additionalInfo);
}
