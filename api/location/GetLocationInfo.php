<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';
use Utils\Env;

SessionUtils::sessionStart();

if (!SessionUtils::isAuthenticated()) {
    $errorMessage = 'You are not authenticated!';
    HttpUtils::sendError('json', $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
}

$requests = HttpUtils::getRequestValues(array('location_id'));

$r_locationId = trim($requests['location_id']);
if (empty($r_locationId)) {
    $ur_locationId = $requests['location_id'];
    HttpUtils::sendError('json', 'location_id kosong!', array('location_id' => 'location_id kosong!'), HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
}
if (!preg_match(PlantIdHelper::locationRegex(), $r_locationId)) {
    $errorMessage = "format location_id [$r_locationId] tidak dikenali!";
    HttpUtils::sendError('json', $errorMessage, array('location_id' => $errorMessage), HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
}

// check if the user is allowed to access the location info.
$user = SessionUtils::getUser();
$r_subplant = PlantIdHelper::usesLocationCell() ? $r_locationId[0] : substr($r_locationId, 0, 2);
assert($r_subplant !== false);
$subplant = PlantIdHelper::usesLocationCell() ? PlantIdHelper::toSubplantId($r_subplant) : $r_subplant;
if (!RequestParamProcessor::validateSubplantId($subplant)) {
    $userid = $user->gua_kode;
    $errorMessage = "User $userid tidak dapat mengakses data subplant [$subplant]!";
    HttpUtils::sendError('json', $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
}
try {
    $db = PostgresqlDatabase::getInstance();
    // check that the location is in the database
    $locationId = $requests['location_id'];
    $queryLocationExists = null;
    if (PlantIdHelper::usesLocationCell()) {
        $queryLocationExists = '
		SELECT 
		    (CASE WHEN get_plant_code() = 2 THEN 2 || iml_plan_kode
		     ELSE iml_plan_kode
		     END) AS plant_code,
			inv_master_lok_pallet.iml_kd_lok AS location_id,
			inv_master_lok_pallet.iml_kd_area AS area_code,
			inv_master_lok_pallet.iml_no_lok AS cell_no,
			inv_master_lok_pallet.iml_no_baris AS line_no,
			inv_master_area.ket_area AS area_name,
			TRUE AS area_enabled
	 	FROM inv_master_lok_pallet INNER JOIN inv_master_area ON
 			inv_master_area.kd_area = inv_master_lok_pallet.iml_kd_area AND 
 			inv_master_area.plan_kode = inv_master_lok_pallet.iml_plan_kode
	 	WHERE inv_master_lok_pallet.iml_kd_lok = $1';
    } else {
        $queryLocationExists = '
		SELECT 
			inv_master_lok_pallet.iml_kd_lok AS location_id,
			(CASE WHEN get_plant_code() = 2 THEN 2 || iml_plan_kode
		     ELSE iml_plan_kode
		     END) AS plant_code,
			inv_master_lok_pallet.iml_kd_area AS area_code,
			inv_master_lok_pallet.iml_no_lok AS line_no,
			inv_master_area.ket_area AS area_name,
			area_status AS area_enabled
	 	FROM inv_master_lok_pallet INNER JOIN inv_master_area ON
 			inv_master_area.kd_area = inv_master_lok_pallet.iml_kd_area AND 
 			inv_master_area.plan_kode = inv_master_lok_pallet.iml_plan_kode
	 	WHERE inv_master_lok_pallet.iml_kd_lok = $1';
    }
    $resCheckLocationCode = $db->parameterizedQuery($queryLocationExists, array($locationId));
    $rowCount = pg_num_rows($resCheckLocationCode);

    $locationDetails = null;
    if ($rowCount === 0) {
        $db->close();
        $msg = "Lokasi dengan kode $locationId tidak ditemukan dalam sistem.";
        HttpUtils::sendError('json', $msg, array('location_no' => $msg), HttpUtils::HTTP_RESPONSE_NOT_FOUND);
    }

    // keep the location details, for the response.
    $locationDetails = pg_fetch_assoc($resCheckLocationCode);
    $db->close();

    $locationDetails['line_no'] = intval($locationDetails['line_no']);
    $locationDetails['area_name'] = trim($locationDetails['area_name']);
    $locationDetails['area_enabled'] = $locationDetails['area_enabled'] === PostgresqlDatabase::PGSQL_TRUE;
    if (PlantIdHelper::usesLocationCell()) {
        $locationDetails['cell_no'] = intval($locationDetails['cell_no']);
    }

    // check if the area is enabled
    if (!$locationDetails['area_enabled']) {
        $locationCode = $locationDetails['area_code'];
        $locationName = $locationDetails['area_name'];

        $msg = "Area $locationCode - $locationName tidak bisa digunakan untuk penempatan!";
        HttpUtils::sendError('json', $msg, array('location_no' => $msg), HttpUtils::HTTP_RESPONSE_FORBIDDEN);
        exit;
    }
    HttpUtils::sendJsonResponse($locationDetails);
} catch (PostgresqlDatabaseException $e) {
    HttpUtils::sendError('json', $e->getMessage(), Env::isDebug() ? $e->getTrace() : array());
}
