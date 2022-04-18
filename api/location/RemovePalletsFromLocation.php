<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';
use Utils\Env;

SessionUtils::sessionStart();

if (!SessionUtils::isAuthenticated()) {
    // print error
    $errorMessage = 'You are not authenticated!';
    HttpUtils::sendError('json', $errorMessage);
    exit;
}
$user = SessionUtils::getUser();

// pallet_nos: comma_separated string, containing pallet id(s).
$requests = HttpUtils::getRequestValues(array('pallet_nos', 'reason'));
$requestErrors = array();

// verify pallet no before sending to DB
$r_palletNos = strtoupper(trim($requests['pallet_nos']));
if (empty($r_palletNos)) {
    $requestErrors['pallet_nos'] = "pallet_nos empty!";
}
$palletNos = explode(',', $r_palletNos);

// verify every palletNos
$malformedPalletNos = array();
foreach ($palletNos as $palletNo) {
    if (!preg_match(PlantIdHelper::palletIdRegex(), $palletNo)) {
        $malformedPalletNos[] = $palletNo;
    }
}
if (count($malformedPalletNos) > 0) {
    $requestErrors['pallet_nos'] = 'Invalid pallet_nos: ' . implode(', ', $malformedPalletNos);
}

// check reason
$ur_reason = $requests['reason'];
$r_reason = trim($ur_reason);
if (empty($r_reason)) {
    $requestErrors['reason'] = 'reason is empty!';
}
$reason = $r_reason;

if (count($requestErrors) > 0) {
    HttpUtils::sendError('json', 'Invalid params!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();

    $userid = $user->gua_kode;
    $query = 'SELECT * FROM remove_pallets_from_location($1,$2,$3)';
    $cursor = $db->parameterizedQuery($query, array($palletNos, $reason, $userid));
    $response = pg_fetch_row($cursor, null, PGSQL_NUM);
    $affectedPallets = $response[0];

    HttpUtils::sendJsonResponse($response, "User [$userid] telah menghapus $affectedPallets palet dari lokasinya.", $userid);
} catch (PostgresqlDatabaseException $e) {
    $errorMessage = $e->getMessage();
    $httpCode = $e->isRaisedManually() ? HttpUtils::HTTP_RESPONSE_FORBIDDEN : HttpUtils::HTTP_RESPONSE_SERVER_ERROR;

    $additionalInfo = Env::isDebug() ? array(
        'query' => $e->getQuery(),
        'db_message' => $e->getOriginalMessage()
    ) : array();
    HttpUtils::sendError('json', $errorMessage, $additionalInfo, $httpCode);
}
