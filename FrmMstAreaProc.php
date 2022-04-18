<?php

use Security\RoleAcl;

require_once __DIR__ . '/vendor/autoload.php';
SessionUtils::sessionStart();
if (!SessionUtils::isAuthenticated()) {
    HttpUtils::sendError('json', 'Not Authenticated!', array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}

$mode = $_GET['mode'];
switch ($mode) {
    case 'save':
        save();
        break;
    case 'get_rows':
        getRowsWithPalletInfo();
        break;
    default:
        view();
        break;
}

function getRowsWithPalletInfo()
{
    if (!UserRole::hasAnyRole(RoleAcl::masterArea())) {
        HttpUtils::sendError('json', 'Not authorized to access master data!', array(), HttpUtils::HTTP_RESPONSE_FORBIDDEN);
        exit;
    }

    $subplant = isset($_GET['subplant']) ? $_GET['subplant'] : '';
    $areaCode = isset($_GET['area_code']) ? $_GET['area_code'] : '';

    $requestErrors = array();
    if (!in_array($subplant, PlantIdHelper::getValidSubplants())) {
        $requestErrors['subplant'] = empty($subplant) ? 'Subplant kosong!' : "Subplant [$subplant] tidak dikenali!";
    }
    if (empty($areaCode)) {
        $requestErrors['area_code'] = 'Kode area kosong!';
    }
    if (count($requestErrors) > 0) {
        HttpUtils::sendError('json', 'Invalid params!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
        exit;
    }

    $db = PostgresqlDatabase::getInstance();
    // check if area exists
    $queryCheckArea = 'SELECT * FROM inv_master_area WHERE plan_kode = $1 AND kd_area = $2';
    $cursorCheckArea = $db->parameterizedQuery($queryCheckArea, array($subplant, $areaCode));
    $resultCheckArea = pg_fetch_assoc($cursorCheckArea);
    if (!$resultCheckArea) {
        $requestErrors['area_code'] = "Tidak ditemukan kode area [$areaCode] pada subplant [$subplant] dalam sistem!";
        HttpUtils::sendError('json', 'Invalid params!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
        exit;
    }

    $query = '
SELECT
  iml_plan_kode                       AS subplant,
  iml_kd_area                         AS area_code,
  iml_kd_lok                          AS location_id,
  iml_no_lok                          AS row_no,
  COALESCE(COUNT(io.io_no_pallet), 0) AS pallet_count,
  COALESCE(SUM(hasilbj.last_qty), 0)  AS current_quantity_sum
    FROM inv_master_lok_pallet iml
  LEFT JOIN inv_opname io
    ON iml.iml_kd_lok = io.io_kd_lok
  LEFT JOIN tbl_sp_hasilbj hasilbj
    ON io.io_no_pallet = hasilbj.pallet_no
  WHERE iml_kd_area = $1
  GROUP BY iml_plan_kode, iml_kd_area, iml_kd_lok, iml_no_lok
  ORDER BY row_no ASC';
    $cursor = $db->parameterizedQuery($query, array($areaCode));

    $pallets = array();
    while ($row = pg_fetch_assoc($cursor)) {
        $row['pallet_count'] = intval($row['pallet_count']);
        $row['current_quantity_sum'] = intval($row['current_quantity_sum']);

        $pallets[] = $row;
    }

    HttpUtils::sendJsonResponse($pallets);
}

function save()
{
    if (!SessionUtils::isAuthenticated()) {
        HttpUtils::sendError('json', 'Not Authenticated!', array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
        exit;
    }
    if (!UserRole::hasAnyRole(RoleAcl::masterAreaModification())) {
        HttpUtils::sendError('json', 'Not authorized to modify master data!', array(), HttpUtils::HTTP_RESPONSE_FORBIDDEN);
        exit;
    }


    $db = PostgresqlDatabase::getInstance();

    $user = SessionUtils::getUser();
    $subplant = isset($_POST['subplant']) ? $_POST['subplant'] : '';
    $areaCode = isset($_POST['area_code']) ? $_POST['area_code'] : '';
    $areaName = isset($_POST['area_name']) ? $_POST['area_name'] : '';
    $rowCount = isset($_POST['row_count']) ? intval($_POST['row_count']) : -1;
    $remarks = isset($_POST['remarks']) ? $_POST['remarks'] : '';
    $area_status = isset($_POST['status']) ? ($_POST['status'] === 'Ya' ? 't' : 'f') : '';

    $requestErrors = array();
    if (!in_array($subplant, PlantIdHelper::getValidSubplants())) {
        $requestErrors['subplant'] = empty($subplant) ? 'Subplant kosong!' : "Subplant [$subplant] tidak dikenali!";
    }
    if (!($rowCount > 0 && $rowCount <= 999)) {
        $requestErrors['row_count'] = $rowCount === -1 ? 'row_count kosong!' : "row_count [" . $_POST['row_count'] . "] tidak valid!";
    }
    if (count($requestErrors) > 0) {
        HttpUtils::sendError('json', 'Invalid params!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
        exit;
    }

    $method = isset($_POST['method']) ? $_POST['method'] : 'create';
    $query = null;
    if ($method === 'edit') {
        // check if area exists
        $queryCheckArea = 'SELECT * FROM inv_master_area WHERE plan_kode = $1 AND kd_area = $2';
        $cursorCheckArea = $db->parameterizedQuery($queryCheckArea, array($subplant, $areaCode));
        $resultCheckArea = pg_fetch_assoc($cursorCheckArea);
        if (!$resultCheckArea) {
            $requestErrors['area_code'] = "Tidak ditemukan kode area [$areaCode] pada subplant [$subplant] dalam sistem!";
            HttpUtils::sendError('json', 'Invalid params!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
            exit;
        }

        // check if rowCount is less than the one in check area
        if ($resultCheckArea['kd_baris'] !== null) {
            $existingRowCount = intval($resultCheckArea['kd_baris']);
            if ($existingRowCount > $rowCount) {
                $requestErrors['row_count'] = "Jumlah baris tidak boleh lebih kecil dari $existingRowCount!";
                HttpUtils::sendError('json', 'Invalid params!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
                exit;
            }
        }

        $query = '
UPDATE inv_master_area 
SET ket_area = $4, kd_baris = $5, remarks = $6, updated_at = CURRENT_TIMESTAMP, updated_by = $3,area_status=$7
WHERE plan_kode = $1 AND kd_area = $2';
    } else if ($method === 'create') {
        $query = '
INSERT INTO inv_master_area 
(plan_kode, kd_area, updated_by, ket_area, kd_baris, remarks, area_status) VALUES ($1, $2, $3, $4, $5, $6, $7)
        ';
    } else {
        HttpUtils::sendError('json', 'Invalid params!', array('method' => "Unknown method: $method", HttpUtils::HTTP_RESPONSE_BAD_REQUEST));
        exit;
    }
    $result = $db->parameterizedQuery($query, array($subplant, $areaCode, $user->gua_kode, $areaName, $rowCount, $remarks, $area_status));
    assert(pg_affected_rows($result) === 1);

    // fetch again the data, and return it to the client.
    $queryCheckArea = 'SELECT * FROM inv_master_area WHERE plan_kode = $1 AND kd_area = $2';
    $cursorCheckArea = $db->parameterizedQuery($queryCheckArea, array($subplant, $areaCode));
    $resultCheckArea = pg_fetch_assoc($cursorCheckArea);
    $db->close();

    $response = array(
        'subplant' => $resultCheckArea['plan_kode'],
        'area_code' => $resultCheckArea['kd_area'],
        'area_name' => trim($resultCheckArea['ket_area']),
        'row_count' => intval($resultCheckArea['kd_baris']),

        'id' => $resultCheckArea['plan_kode'] . $resultCheckArea['kd_area'],
        'remarks' => $resultCheckArea['remarks'],
        'updated_at' => $resultCheckArea['updated_at'],
        'updated_by' => $resultCheckArea['updated_by'],
        'area_status' => $resultCheckArea['area_status'] === 't' ? "Ya" : "Tidak"
    );

    HttpUtils::sendJsonResponse($response);
}

function view()
{
    $db = PostgresqlDatabase::getInstance();

    $sql = "select * from inv_master_area order by area_status DESC";
    $cursor = $db->rawQuery($sql);
    $i = 1;
    $areas = array();
    while ($row = pg_fetch_assoc($cursor)) {
        $areas[] = array(
            'subplant' => $row['plan_kode'],
            'area_code' => $row['kd_area'],
            'area_name' => trim($row['ket_area']),
            'row_count' => intval($row['kd_baris']),

            'no' => $i++,
            'id' => $row['plan_kode'] . $row['kd_area'],
            'remarks' => $row['remarks'],
            'updated_at' => $row['updated_at'],
            'updated_by' => $row['updated_by'],
            'area_status' => $row['area_status'] === 't' ? 'Ya' : 'Tidak'
        );
    }
    $db->close();
    HttpUtils::sendJsonResponse($areas);
}
