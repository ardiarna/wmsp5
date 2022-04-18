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
$response = array('is_authorized' => UserRole::isAuthorizedForEditMasterData($user->roles));
switch ($mode) {
    case HttpUtils::MODE_JSON:
        HttpUtils::sendJsonResponse($response, '', $userid);
        break;
    case HttpUtils::MODE_XML:
        $response = array(
            'id' => $userid,
            'is_authorized' => $response['is_authorized']
        );
        HttpUtils::sendXmlResponse($response, 'id', 'user');
        break;
}
exit;
