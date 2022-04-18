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
    $allowedRoles = RoleAcl::downgradePalletsReasonModification();
    $authorized = UserRole::hasAnyRole($allowedRoles);
}

if (!$authorized) {
    $errorMessage = 'You are not authorized to modify downgrade data!';
    HttpUtils::sendError('json', $errorMessage, array(), HttpUtils::HTTP_RESPONSE_FORBIDDEN);
    exit;
}

// get and validate request
$requests = HttpUtils::getRequestValues(array('id', 'is_disabled'));
$requestErrors = array();

$id = $requests['id'];
if (empty($id)) {
    $requestErrors['id'] = 'id is empty!';
}

$isDisabled = null;
try {
    $isDisabled = RequestParamProcessor::getBoolean($requests['is_disabled']);
} catch (InvalidArgumentException $e) {
    $requestErrors['is_disabled'] = $e->getMessage();
}

if (!empty($requestErrors)) {
    HttpUtils::sendError('json', 'Kesalahan pada permintaan data!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();

    // check if the data exists
    $queryCheck = <<<EOT
    SELECT * FROM tbl_sp_ket_dg_pallet WHERE id = $1;
EOT;
    $resultCheck = $db->parameterizedQuery($queryCheck, array($id));
    if (pg_num_rows($resultCheck) === 0) {
        $db->close();
        $errorMessage = "Ket. downgrade dengan id '$id' tidak ditemukan!";
        HttpUtils::sendError('json', $errorMessage, array('id' => $errorMessage), HttpUtils::HTTP_RESPONSE_NOT_FOUND);
        exit;
    }

    $userid = $user->gua_kode;
    $queryUpdate = <<<EOT
    UPDATE tbl_sp_ket_dg_pallet
    SET is_disabled = $2,
        updated_at = now(),
        updated_by = $3
    WHERE id = $1
    RETURNING *;
EOT;
    $params = array($id, $isDisabled, $userid);
    $resultUpdate = $db->parameterizedQuery($queryUpdate, $params);
    assert(pg_num_rows($resultUpdate) === 1);
    $updatedReason = pg_fetch_assoc($resultUpdate);
    $db->close();

    $updatedReason['id'] = intval($updatedReason['id']);
    $updatedReason['is_disabled'] = QueryResultConverter::toBool($updatedReason['is_disabled']);
    HttpUtils::sendJsonResponse($updatedReason, '', $userid);
} catch (PostgresqlDatabaseException $e) {
    HttpUtils::sendError('json', (string)$e,
        Env::isDebug() ? $e->getTrace() : array(),
        $e->isRaisedManually() ? HttpUtils::HTTP_RESPONSE_UNPROCESSABLE_ENTITY : HttpUtils::HTTP_RESPONSE_SERVER_ERROR
    );
}

