<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';
use Utils\Env;

SessionUtils::sessionStart();

if (!SessionUtils::isAuthenticated()) {
    $errorMessage = 'You are not authenticated!';
    HttpUtils::sendError('json', $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
}

define('QUANTITY_EMPTY', 0);
define('QUANTITY_NONEXISTENT', -1);
define('QUANTITY_NOT_VERIFIED_BY_QA', -2);
define('QUANTITY_NOT_HANDED_OVER', -3);
define('QUANTITY_CANCELLED', -4);

// pallet_nos: comma_separated string, containing pallet id(s).
$requests = HttpUtils::getRequestValues(array('pallet_nos', 'location_id'));
$requestErrors = array();

// verify pallet no before sending to DB
$r_palletNos = strtoupper(trim($requests['pallet_nos']));
if (empty($r_palletNos)) {
    $requestErrors['pallet_nos'] = "pallet_nos empty!";
}

$palletNos = explode(',', $r_palletNos);
$singlePallet = count($palletNos) === 1;

// verify every palletNos
$malformedPalletNos = array();
foreach($palletNos as $palletNo) {
    if (!preg_match(PlantIdHelper::palletIdRegex(), $palletNo)) {
        $malformedPalletNos[] = $palletNo;
    }
}
if (count($malformedPalletNos) > 0) {
    $requestErrors['pallet_nos'] = 'Invalid pallet_nos: ' . implode(', ', $malformedPalletNos);
}

// verify location id
$newLocationId = strtoupper(trim($requests['location_id']));
if (empty($newLocationId)) {
    $requestErrors['location_id'] = "location_id empty!";
} else if (!preg_match(PlantIdHelper::locationRegex(), $newLocationId)) {
    $requestErrors['location_id'] = "Invalid location_id [$newLocationId]";
}

if (count($requestErrors) > 0) {
    HttpUtils::sendError('json', 'Invalid request params!', $requestErrors,HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
}

try {
    $db = PostgresqlDatabase::getInstance();

    $user = SessionUtils::getUser();
    $userid = $user->gua_kode;

    // check if user is authorized.
    $queryCheckAuthorized = 'SELECT * FROM check_user_can_move_pallets_in_warehouse($1, $2)';
    $resultCheckAuthorized = $db->parameterizedQuery($queryCheckAuthorized, array($palletNos, $userid));
    $isAuthorizedRes = pg_fetch_row($resultCheckAuthorized, null, PGSQL_NUM);
    $isAuthorized = $isAuthorizedRes[0] === PostgresqlDatabase::PGSQL_TRUE;
    if (!$isAuthorized) {
        $errorMessage = null;
        if ($singlePallet) {
            $palletNo = $palletNos[0];
            $errorMessage = "User [$userid] tidak punya otoritas untuk memasukkan/memindahkan palet [$palletNo]!";
        } else {
            $palletNo = implode(', ', $palletNos);
            $errorMessage = "User [$userid] tidak punya otoritas untuk memasukkan/memindahkan salah satu/semua palet [$palletNo]!";
        }
        HttpUtils::sendError('json', $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    }

    // check pallet existence and validity (quantity, handed over, exists)
    $queryCheckPallet = '
      SELECT * FROM get_multiple_pallet_locations_with_quantity($1)
      AS pallet_records (pallet_no VARCHAR, subplant VARCHAR, current_location_no VARCHAR, current_quantity INT);
    ';
    $resultCheckPallet = $db->parameterizedQuery($queryCheckPallet, array($palletNos));

    $notVerifiedByQAPallets = array();
    $emptyPallets = array();
    $nonexistentPallets = array();
    $notHandedOverPallets = array();
    $cancelledPallets = array();

    // flags for invalid pallets
    $hasNotVerifiedByQAPallets = false;
    $hasEmptyPallets = false;
    $hasNonexistentPallets = false;
    $hasNotHandedOverPallets = false;
    $hasCancelledPallets = false;

    $singlePalletOldLocationId = false;
    while ($row = pg_fetch_assoc($resultCheckPallet)) {
        if ($singlePallet) {
            $singlePalletOldLocationId = $row['current_location_no'];
        }

        $currentPalletQuantity = $row['current_quantity'] = intval($row['current_quantity']);
        if ($currentPalletQuantity === QUANTITY_EMPTY) {
            $emptyPallets[] = $row['pallet_no'];
            $hasEmptyPallets = true;
        } else if ($currentPalletQuantity === QUANTITY_NONEXISTENT) {
            $nonexistentPallets[] = $row['pallet_no'];
            $hasNonexistentPallets = true;
        } else if ($currentPalletQuantity === QUANTITY_NOT_VERIFIED_BY_QA) {
            $notVerifiedByQAPallets[] = $row['pallet_no'];
            $hasNotVerifiedByQAPallets = true;
        } else if ($currentPalletQuantity === QUANTITY_NOT_HANDED_OVER) {
            $notHandedOverPallets[] = $row['pallet_no'];
            $hasNotHandedOverPallets = true;
        } else if ($currentPalletQuantity === QUANTITY_CANCELLED) {
            $cancelledPallets[] = $row['pallet_no'];
            $hasCancelledPallets = true;
        }
    }

    $hasInvalidPallets = $hasEmptyPallets || $hasNonexistentPallets || $hasNotVerifiedByQAPallets || $hasNotHandedOverPallets || $hasCancelledPallets;
    if ($hasInvalidPallets) {
        // send error message
        $response = array();
        $errorMessage = null;
        if ($singlePallet) {
            $palletNo = $palletNos[0];
            $errorMessage = $hasEmptyPallets ? "Palet [$palletNo] kosong!" :
                    $hasNonexistentPallets ? "Palet [$palletNo] tidak ada dalam sistem!" :
                    $hasNotVerifiedByQAPallets ? "Palet [$palletNo] belum diverifikasi oleh QA!" :
                        "Palet [$palletNo] tidak valid!";
        } else {
            $response = array(
                'cancelled' => $cancelledPallets,
                'not_handed_over' => $notHandedOverPallets,
                'not_verified_by_qa' => $notVerifiedByQAPallets,
                'not_found' => $nonexistentPallets,
                'empty' => $emptyPallets
            );
            $errorMessage = 'Ada palet tidak valid yang mau dimasukkan ke dalam lokasi!';
        }
        HttpUtils::sendError('json', $errorMessage, $response, HttpUtils::HTTP_RESPONSE_FORBIDDEN);
    }

    // check location.
    $queryLocation = 'SELECT * FROM inv_master_lok_pallet WHERE iml_kd_lok = $1';
    $resultLocation = $db->parameterizedQuery($queryLocation, array($newLocationId));
    if (pg_num_rows($resultLocation) === 0) {
        $errorMessage = "No. Lokasi [$newLocationId] tidak ditemukan dalam sistem!";
        HttpUtils::sendError('json', $errorMessage, array(), HttpUtils::HTTP_RESPONSE_NOT_FOUND);
    }

    // update pallet.
    $queryUpdateLocation = 'SELECT * FROM batch_update_pallet_location($1, $2, $3)';
    $resultUpdateLocation = $db->parameterizedQuery($queryUpdateLocation, array($palletNos, $newLocationId, $userid));
    $rowUpdateLocation = pg_fetch_row($resultUpdateLocation, null, PGSQL_NUM);
    assert($rowUpdateLocation !== false);

    // log
    $logMessage = "User [$userid] telah ";
    if ($singlePallet) {
        $palletNo = $palletNos[0];
        $logMessage .= is_null($singlePalletOldLocationId) ? "memasukkan palet [$palletNo] ke $newLocationId" :
            "memindahkan palet [$r_palletNos] dari $singlePalletOldLocationId ke $newLocationId.";
    } else {
        $palletsAffected = intval($rowUpdateLocation[0]);
        $logMessage .= "memasukkan $palletsAffected palet ke $newLocationId.";
    }
    HttpUtils::sendJsonResponse(array(), $logMessage, $userid);
} catch (PostgresqlDatabaseException $e) {
    $httpCode = $e->isRaisedManually() ? HttpUtils::HTTP_RESPONSE_FORBIDDEN : HttpUtils::HTTP_RESPONSE_SERVER_ERROR;
    HttpUtils::sendError('json', $e->getMessage(), Env::isDebug() ? $e->getTrace() : array(), $httpCode);
} catch (Exception $e) {
    HttpUtils::sendError('json', $e->getMessage(), Env::isDebug() ? $e->getTrace() : array());
}
