<?php
require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

SessionUtils::sessionStart();

$requestParams = HttpUtils::getRequestValues(array('mode'), 'json');
$mode = $requestParams['mode'];
if (!HttpUtils::isValidMode($mode)) {
    HttpUtils::sendError($mode, '', array(), HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
}

if (!SessionUtils::isAuthenticated()) {
    // print error
    $errorMessage = 'You are not authenticated!';
    HttpUtils::sendError($mode, $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}
$user = SessionUtils::getUser();
if (empty($user->gua_subplant_handover)) {
    $errorMessage = 'You are not authorized to access any mutation data!';
    HttpUtils::sendError($mode, $errorMessage, array(), HttpUtils::HTTP_RESPONSE_FORBIDDEN);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();

    $query = '
SELECT 
  COALESCE((SELECT mv_last_updated_at 
  FROM db_maintenance.meta_mv_refresh 
  WHERE mv_name = \'gbj_report.summary_mutation_by_motif_size_shading\'), now())::TIMESTAMP 
    AS timestamp,
  MIN(mutation_date) AS min_date,
  MAX(mutation_date) AS max_date
FROM gbj_report.summary_mutation_by_motif_size_shading';
    $cursor = $db->rawQuery($query);
    $row = pg_fetch_assoc($cursor);
    if (!$row) {
        $db->close();
        HttpUtils::sendError($mode, 'No mutation data available!', array(), HttpUtils::HTTP_RESPONSE_NOT_FOUND);
        exit;
    }

    $db->close();
    $timestamp = new DateTime($row['timestamp']);

    // do calculation for maxDate
    $today = new DateTime();
    $today->setTime(0, 0);

    $maxDate = DateTime::createFromFormat(PostgresqlDatabase::PGSQL_DATE_FORMAT, $row['max_date']);
    $maxDate->setTime(0, 0);

    $maxDateExport = $maxDate < $today ? $maxDate : $today;

    $data = array(
        'min_date' => $row['min_date'],
        'max_date' => $maxDateExport->format(PostgresqlDatabase::PGSQL_DATE_FORMAT),
        'generated_at' => $timestamp->format(DATE_ISO8601)
    );
    switch ($mode) {
        case HttpUtils::MODE_XML:
            HttpUtils::sendXmlResponse($data);
            break;
        case HttpUtils::MODE_JSON:
            HttpUtils::sendJsonResponse($data, '', $user->gua_kode);
            break;
    }
} catch (PostgresqlDatabaseException $e) {
    $errorMessage = $e->getMessage();
    $additionalInfo = array(
        'query' => $e->getQuery(),
        'db_message' => $e->getOriginalMessage()
    );
    HttpUtils::sendError($mode, $errorMessage, $additionalInfo);
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    HttpUtils::sendError($mode, $errorMessage);
}
