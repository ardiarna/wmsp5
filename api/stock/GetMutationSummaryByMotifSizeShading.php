<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';
use Security\RoleAcl;

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

// check authorization.
$authorized = !empty($user->gua_subplants);
if ($authorized) {
    // check role
    // for now only allow kabag and above to see the data.
    $allowedRoles = RoleAcl::mutationReport();
    $authorized = UserRole::hasAnyRole($allowedRoles);
}

if (!$authorized) {
    $errorMessage = 'You are not authorized to access mutation data!';
    HttpUtils::sendError($mode, $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}

$errors = array();
$requests = HttpUtils::getRequestValues(array('subplant', 'date_from', 'date_to', 'motif_ids'), null);

$subplant = $requests['subplant'];
if (!isset($subplant)) {
    $errors['subplant'] = 'Empty subplant!';
} else if (!RequestParamProcessor::validateSubplantId($subplant) && $subplant !== 'all') {
    $errors['subplant'] = "Unknown subplant [$subplant]!";
}

$us_dateFrom = $requests['date_from'];
$dateFrom = null;
if(!isset($us_dateFrom)) {
    $errors['date_from'] = 'Empty date!';
} else {
    try {
        $dateFrom = RequestParamProcessor::getLocalDate($us_dateFrom);
    } catch (InvalidArgumentException $e) {
        $errors['date_from'] = 'Invalid date [' . $us_dateFrom . ']';
    }
}

$us_dateTo = $requests['date_to'];
$dateTo = null;
if(!isset($us_dateTo)) {
    $errors['date_to'] = 'Empty date!';
} else {
    try {
        $dateTo = RequestParamProcessor::getLocalDate($us_dateTo);
    } catch (InvalidArgumentException $e) {
        $errors['date_to'] = 'Invalid date [' . $us_dateTo . ']';
    }
}

$motifIds = $requests['motif_ids'] ?: array();

if (count($errors) > 0) {
    $errorMessage = 'Bad request!';
    HttpUtils::sendError($mode, $errorMessage, $errors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();

    // setup parameters
    $s_fromDate = $dateFrom->format(PostgresqlDatabase::PGSQL_DATE_FORMAT);
    $s_toDate = $dateTo->format(PostgresqlDatabase::PGSQL_DATE_FORMAT);
    $params = array($s_fromDate, $s_toDate);
    $paramCount = count($params);

    if ($subplant !== 'all') {
        $params[] = $subplant;
    }
    if (count($motifIds) > 0) {
        $params[] = $motifIds;
    }

    $subplantFilter = $subplant !== 'all' ? 'subplant = $' . ++$paramCount : '1=1';
    $motifFilter = count($motifIds) > 0 ? 'motif_id = ANY($' . ++$paramCount . ')' : '1=1';

    // setup query
    $query = "
SELECT 
    COALESCE(a.subplant, b.subplant) subplant, 
    COALESCE(a.motif_dimension, b.motif_dimension) motif_dimension, 
    COALESCE(a.motif_id, b.motif_id) motif_id, 
    COALESCE(a.motif_name, b.motif_name) motif_name,
    COALESCE(a.size, b.size) size,
    COALESCE(a.shading, b.shading) shading,
    COALESCE(b.initial_quantity, 0) initial_quantity,
    COALESCE(prod_initial_quantity, 0) prod_initial_quantity,
    COALESCE(manual_initial_quantity, 0) manual_initial_quantity,
    COALESCE(in_mut_quantity, 0) in_mut_quantity,
    COALESCE(out_mut_quantity, 0) out_mut_quantity,
    COALESCE(in_adjusted_quantity, 0) in_adjusted_quantity,
    COALESCE(out_adjusted_quantity, 0) out_adjusted_quantity,
    COALESCE(returned_quantity, 0) returned_quantity,
    COALESCE(broken_quantity, 0) broken_quantity,
    COALESCE(sales_in_progress_quantity, 0) AS sales_in_progress_quantity,
    COALESCE(sales_confirmed_quantity, 0) AS sales_confirmed_quantity,
    COALESCE(foc_quantity, 0) foc_quantity,
    COALESCE(sample_quantity, 0) sample_quantity,
    COALESCE(in_downgrade_quantity, 0) in_downgrade_quantity,
    COALESCE(out_downgrade_quantity, 0) out_downgrade_quantity
FROM (
    SELECT t1.subplant, t1.motif_dimension, t1.motif_id, t1.motif_name, size, shading,
      SUM(t1.prod_initial_quantity) AS prod_initial_quantity,
      SUM(t1.manual_initial_quantity) AS manual_initial_quantity,
      SUM(t1.in_mut_quantity) AS in_mut_quantity,
      SUM(t1.out_mut_quantity) AS out_mut_quantity,
      SUM(t1.in_adjusted_quantity) AS in_adjusted_quantity,
      SUM(t1.out_adjusted_quantity) AS out_adjusted_quantity,
      SUM(t1.returned_quantity) AS returned_quantity,
      SUM(t1.broken_quantity) AS broken_quantity,
      SUM(t1.sales_in_progress_quantity) AS sales_in_progress_quantity,
      SUM(t1.sales_confirmed_quantity) AS sales_confirmed_quantity,
      SUM(t1.foc_quantity) AS foc_quantity,
      SUM(t1.sample_quantity) AS sample_quantity,
      SUM(t1.in_downgrade_quantity) AS in_downgrade_quantity,
      SUM(t1.out_downgrade_quantity) AS out_downgrade_quantity
    FROM gbj_report.summary_mutation_by_motif_size_shading t1  
    WHERE mutation_date BETWEEN $1 AND $2
      AND $subplantFilter
      AND $motifFilter
    GROUP BY t1.subplant, t1.motif_dimension, t1.motif_id, t1.motif_name, size, shading
) a
FULL JOIN
(SELECT subplant, motif_id, motif_name, motif_dimension, size, shading,
        SUM(t2.prod_initial_quantity + t2.manual_initial_quantity 
             + t2.in_mut_quantity - out_mut_quantity 
             + t2.in_adjusted_quantity - t2.out_adjusted_quantity 
             - t2.returned_quantity
             - t2.broken_quantity
             - t2.sales_in_progress_quantity
             - t2.sales_confirmed_quantity
             - t2.foc_quantity
             - t2.sample_quantity
             + t2.in_downgrade_quantity
             - t2.out_downgrade_quantity) AS initial_quantity
  FROM gbj_report.summary_mutation_by_motif_size_shading t2
  WHERE mutation_date < $1
    AND $subplantFilter
    AND $motifFilter
  GROUP BY subplant, motif_dimension, motif_id, motif_name, size, shading
  HAVING SUM(t2.prod_initial_quantity + t2.manual_initial_quantity 
             + t2.in_mut_quantity - out_mut_quantity 
             + t2.in_adjusted_quantity - t2.out_adjusted_quantity 
             - t2.returned_quantity
             - t2.broken_quantity
             - t2.sales_in_progress_quantity
             - t2.sales_confirmed_quantity
             - t2.foc_quantity
             - t2.sample_quantity
             + t2.in_downgrade_quantity
             - t2.out_downgrade_quantity) <> 0
) b ON a.subplant = b.subplant AND a.motif_id = b.motif_id
ORDER BY subplant, motif_dimension, motif_name, size, shading";
    $cursor = $db->parameterizedQuery($query, $params);
    $results = array();

    while($row = pg_fetch_assoc($cursor)) {
        $row = QueryResultConverter::toInt($row, array(
            'initial_quantity',
            'prod_initial_quantity', 'manual_initial_quantity',
            'in_mut_quantity', 'out_mut_quantity',
            'in_adjusted_quantity', 'out_adjusted_quantity',
            'returned_quantity', 'broken_quantity',
            'sales_in_progress_quantity', 'sales_confirmed_quantity',
            'foc_quantity', 'sample_quantity',
            'in_downgrade_quantity', 'out_downgrade_quantity'
        ));
        $row['in_quantity_total'] = $row['prod_initial_quantity'] + $row['manual_initial_quantity']
            + $row['in_mut_quantity']
            + $row['in_adjusted_quantity']
            + $row['in_downgrade_quantity'];
        $row['out_quantity_total'] = $row['out_mut_quantity']
            + $row['out_adjusted_quantity']
            + $row['returned_quantity'] + $row['broken_quantity']
            + $row['sales_in_progress_quantity'] + $row['sales_confirmed_quantity']
            + $row['foc_quantity'] + $row['sample_quantity']
            + $row['out_downgrade_quantity'];
        $row['final_quantity'] = $row['initial_quantity'] + $row['in_quantity_total'] - $row['out_quantity_total'];
        $results[] = $row;
    }

    $queryLastUpdatedAt = "
    SELECT mv_last_updated_at 
    FROM db_maintenance.meta_mv_refresh 
    WHERE mv_name = 'gbj_report.summary_mutation_by_motif_size_shading'";
    $cursorLastUpdatedAt = $db->rawQuery($queryLastUpdatedAt);
    assert(pg_num_rows($cursorLastUpdatedAt) === 1);

    $resultLastUpdatedAt = pg_fetch_row($cursorLastUpdatedAt);
    $lastUpdatedAt = new DateTime($resultLastUpdatedAt[0]);

    $db->close();
    $response = array(
        'data' => $results,
        'last_updated_at' => $lastUpdatedAt->format(DATE_ISO8601)
    );

    switch($mode) {
        case HttpUtils::MODE_XML:
            HttpUtils::sendXmlResponse($response);
            break;
        case HttpUtils::MODE_JSON:
            HttpUtils::sendJsonResponse($response, '', $user->gua_kode);
            break;
    }
} catch (PostgresqlDatabaseException $e) {
    $errorMessage = $e->getMessage();
    $additionalInfo = array(
        'query' => $e->getQuery(),
        'db_message' => $e->getOriginalMessage()
    );
    HttpUtils::sendError($mode, $errorMessage, $additionalInfo);
}
