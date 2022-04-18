<?php
require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

SessionUtils::sessionStart();

if (!SessionUtils::isAuthenticated()) {
    // print error
    $errorMessage = 'You are not authenticated!';
    HttpUtils::sendError('json', $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}
if (!UserRole::isSuperuser()) {
    $errorMessage = 'You are not authorized!';
    HttpUtils::sendError('json', $errorMessage, array(), HttpUtils::HTTP_RESPONSE_FORBIDDEN);
    exit;
}

$requests = HttpUtils::getRequestValues(array('new_role'));
// check available roles
$refl = (new ReflectionClass(UserRole::getFullClassName()));
$roles = array_values($refl->getConstants());

if (!in_array($requests['new_role'], $roles, true)) {
    $errorMessage = "Unknown role [${requests['new_role']}]!";
    HttpUtils::sendError('json', $errorMessage, array('new_role' => $errorMessage), HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

$user = SessionUtils::getUser();
assert(isset($user));

$user->roles = array($requests['new_role']);
$_SESSION['user'] = $user;
HttpUtils::sendJsonResponse($_SESSION, '', $user->gua_kode);
