<?php

use Utils\Env;

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

SessionUtils::sessionStart();

if (!SessionUtils::isAuthenticated()) {
    // print error
    $errorMessage = 'You are not authenticated!';
    HttpUtils::sendError('json', $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}

$user = SessionUtils::getUser();
assert($user !== null && !empty($user));

$requests = HttpUtils::getRequestValues(array('current_password', 'new_password', 'new_password_confirm'));

// validate request body
$requestErrors = array();
if (!isset($requests['current_password']) || $requests['current_password'] === '' || !is_string($requests['current_password'])) {
    $requestErrors['current_password'] = 'current_password is empty!';
}
if (!isset($requests['new_password']) || $requests['new_password'] === '' || !is_string($requests['new_password'])) {
    $requestErrors['new_password'] = 'new_password is empty!';
}
if (!isset($requests['new_password_confirm']) || $requests['new_password_confirm'] === '' || !is_string($requests['new_password_confirm'])) {
    $requestErrors['new_password_confirm'] = 'new_password_confirm is empty!';
}
if (!empty($requestErrors)) {
    $errorMessage = 'bad request!';
    HttpUtils::sendError('json', $errorMessage, $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

// validate password against new/old
if ($requests['current_password'] === $requests['new_password']) {
    $requestErrors['new_password'] = 'Password Baru tidak boleh sama dengan Password Lama!';
}
if ($requests['new_password'] !== $requests['new_password_confirm']) {
    $requestErrors['new_password_confirm'] = 'Password Baru (Konfirm) harus sama dengan Password Baru!';
}
const MIN_PASSWORD_CHARS = 8;
if (strlen($requests['new_password']) < MIN_PASSWORD_CHARS) {
    $requestErrors['new_password'] = 'Password baru terlalu pendek! Minimal ' . MIN_PASSWORD_CHARS . ' karakter';
}

try {
    $db = PostgresqlDatabase::getInstance();
    $query = 'SELECT * FROM gen_user_adm WHERE gua_kode = $1 AND gua_pass = crypt($2, gua_pass)';
    $cursor = $db->parameterizedQuery($query, array($user->gua_kode, $requests['current_password']));
    if (pg_num_rows($cursor) === 0) {
        $requestErrors['current_password'] = 'Password saat ini salah!';
    }

    if (!empty($requestErrors)) {
        $db->close();
        $errorMessage = 'Perubahan password gagal!';
        $requestErrors['userid'] = $user->gua_kode;
        HttpUtils::sendError('json', $errorMessage, $requestErrors, HttpUtils::HTTP_RESPONSE_FORBIDDEN);
        exit;
    }

    $queryUpdate = "UPDATE gen_user_adm SET gua_pass = crypt($2, gen_salt('bf')), gua_last_pass_reset = now(), gua_last_updated_at = now() WHERE gua_kode = $1";
    $cursor = $db->parameterizedQuery($queryUpdate, array($user->gua_kode, $requests['new_password']));
    assert(pg_affected_rows($cursor) === 1);

    $db->close();
    HttpUtils::sendJsonResponse(array(), 'Password berhasil diganti!', $user->gua_kode);
} catch (PostgresqlDatabaseException $e) {
    $errorMessage = $e->getMessage();
    $additionalInfo = array(
        'query' => $e->getQuery(),
        'db_message' => $e->getOriginalMessage()
    );
    if (Env::isDebug()) {
        $additionalInfo['trace'] = $e->getTrace();
    }
    HttpUtils::sendError('json', $errorMessage, $additionalInfo,
        $e->isRaisedManually() ? HttpUtils::HTTP_RESPONSE_UNPROCESSABLE_ENTITY : HttpUtils::HTTP_RESPONSE_SERVER_ERROR
    );
}
