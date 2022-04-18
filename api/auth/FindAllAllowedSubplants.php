<?php
require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

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
switch ($mode) {
    case HttpUtils::MODE_JSON:
        HttpUtils::sendJsonResponse($subplants, '', $userid);
        break;
    case HttpUtils::MODE_XML:
        $response = array(
            'id' => $userid,
            'subplants' => $subplants
        );
        HttpUtils::sendXmlResponse($response, 'id', 'user');
        break;
}
exit;
