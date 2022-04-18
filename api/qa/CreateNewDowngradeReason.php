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
$requests = HttpUtils::getRequestValues(array('reason'));
$requestErrors = array();

const MIN_REASON_LENGTH = 3;
$reason = $requests['reason'];
if (empty($reason)) {
    $requestErrors['reason'] = 'reason is empty!';
} elseif (strlen($reason) < MIN_REASON_LENGTH) {
    $requestErrors['reason'] = 'reason should be at least ' . MIN_REASON_LENGTH . ' characters long!';
}

if (!empty($requestErrors)) {
    HttpUtils::sendError('json', 'Kesalahan pada permintaan data!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();

    // check if there has already been something like it previously.
    $queryCheck = <<<EOT
    SELECT * FROM tbl_sp_ket_dg_pallet WHERE reason = UPPER($1);
EOT;
    $resultCheck = $db->parameterizedQuery($queryCheck, array($reason));
    if (pg_num_rows($resultCheck) > 0) {
        $db->close();
        $errorMessage = "Ket. downgrade '$reason' sudah ada!";
        HttpUtils::sendError('json', $errorMessage, array('reason' => $errorMessage), HttpUtils::HTTP_RESPONSE_UNPROCESSABLE_ENTITY);
        exit;
    }

    $userid = $user->gua_kode;
    $queryCreate = <<<EOT
    INSERT INTO tbl_sp_ket_dg_pallet(reason, plan_kode, updated_at, updated_by, created_by)
    VALUES($1, get_plant_code()::VARCHAR, now(), $2, $2)
    RETURNING *;
EOT;
    $params = array($reason, $userid);
    $resultCreate = $db->parameterizedQuery($queryCreate, $params);
    assert(pg_num_rows($resultCreate) === 1);
    $newReason = pg_fetch_assoc($resultCreate);
    $db->close();

    $newReason['id'] = intval($newReason['id']);
    $newReason['is_disabled'] = QueryResultConverter::toBool($newReason['is_disabled']);
    HttpUtils::sendJsonResponse($newReason, '', $userid);
} catch (PostgresqlDatabaseException $e) {
    HttpUtils::sendError('json', (string)$e,
        Env::isDebug() ? $e->getTrace() : array(),
        $e->isRaisedManually() ? HttpUtils::HTTP_RESPONSE_UNPROCESSABLE_ENTITY : HttpUtils::HTTP_RESPONSE_SERVER_ERROR
    );
}
