<?php
require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

use Utils\Env;

SessionUtils::sessionStart();

$requestParams = HttpUtils::getRequestValues(array('mode'), 'xml');
$mode = HttpUtils::isValidMode($requestParams['mode']) ? $requestParams['mode'] : '';

if (!SessionUtils::isAuthenticated()) {
    // print error
    $errorMessage = 'You are not authenticated!';
    HttpUtils::sendError($mode, $errorMessage);
    exit;
}

$user = SessionUtils::getUser();
assert($user != null);

$userid = $user->gua_kode;
$subplants = $user->gua_subplants;
$roles = $user->roles;
$subplantsHandover = $user->gua_subplant_handover;
$syncPlants = Env::has('SYNC_PLANTS');
$response = array(
    'id' => $userid,
    'userid' => $userid,
    'subplants' => $subplants,
    'subplants_handover' => $user->gua_subplant_handover,
    'roles' => $roles,
    'subplants_other' => $syncPlants
);
switch ($mode) {
    case HttpUtils::MODE_JSON:
        unset($response['id']);
        HttpUtils::sendJsonResponse($response, '', $userid);
        exit;
        break;
    case HttpUtils::MODE_XML:
        HttpUtils::sendXmlResponse($response, 'id', 'user');
        exit;
        break;
}
