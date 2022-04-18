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
    $allowedRoles = RoleAcl::downgradePallets();
    $authorized = UserRole::hasAnyRole($allowedRoles);
}

if (!$authorized) {
    $errorMessage = 'You are not authorized to access downgrade data!';
    HttpUtils::sendError('json', $errorMessage, array(), HttpUtils::HTTP_RESPONSE_FORBIDDEN);
    exit;
}

// validate
$requestErrors = array();
$requests = HttpUtils::getRequestValues(array('is_disabled'), null);
$isDisabled = $requests['is_disabled'];
if (isset($isDisabled)) {
    try {
        $isDisabled = RequestParamProcessor::getBoolean($isDisabled);
    } catch (InvalidArgumentException $e) {
        $requestErrors['is_disabled'] = $e->getMessage();
    }
}
if (!empty($requestErrors)) {
    HttpUtils::sendError('json', 'Kesalahan pada permintaan data!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

if (!$isDisabled) { // allow access to disabled/all only if the user can approve
    $authorized &= UserRole::hasAnyRole(RoleAcl::downgradePalletsApproval());
    if (!$authorized) {
        HttpUtils::sendError('json', 'Anda tidak punya otoritas untuk mengakses keterangan downgrade yang nonaktif!', array(), HttpUtils::HTTP_RESPONSE_FORBIDDEN);
    }
}

try {
    $db = PostgresqlDatabase::getInstance();
    $queryEnabledOnly = is_bool($isDisabled) ? 'is_disabled = $1' : '1=1';

    // check if there has already been something like it previously.
    $query = "SELECT * FROM tbl_sp_ket_dg_pallet WHERE $queryEnabledOnly ORDER BY id;";
    $cursor = is_bool($isDisabled) ? $db->parameterizedQuery($query, array($isDisabled)) : $db->rawQuery($query);
    $reasons = array();
    while ($row = pg_fetch_assoc($cursor)) {
        $row['id'] = intval($row['id']);
        $row['is_disabled'] = QueryResultConverter::toBool($row['is_disabled']);
        $reasons[] = $row;
    }

    $userid = $user->gua_kode;
    $db->close();

    HttpUtils::sendJsonResponse($reasons, '', $userid);
} catch (PostgresqlDatabaseException $e) {
    HttpUtils::sendError('json', (string)$e,
        Env::isDebug() ? $e->getTrace() : array(),
        $e->isRaisedManually() ? HttpUtils::HTTP_RESPONSE_UNPROCESSABLE_ENTITY : HttpUtils::HTTP_RESPONSE_SERVER_ERROR
    );
}
