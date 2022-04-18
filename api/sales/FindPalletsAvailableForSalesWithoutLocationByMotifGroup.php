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

// validate request params
$queryParams = HttpUtils::getRequestValues(array('motif_specs', 'subplant', 'location_subplant'));
$requestErrors = array();

$motifSpecs = $queryParams['motif_specs'];
if (!is_array($motifSpecs) && $motifSpecs !== '') {
    $requestErrors['motif_specs'] = 'motif_specs should be an array!';
}

$subplant = trim($queryParams['subplant']);
if (!RequestParamProcessor::validateSubplantId($subplant) && !in_array($subplant, PlantIdHelper::getAggregateQueryTypes())) {
    if (empty($subplant)) {
        $requestErrors['subplant'] = 'subplant is empty!';
    } else {
        $requestErrors['subplant'] = "Unknown subplant [$subplant]!";
    }
}

$locationSubplant = trim($queryParams['location_subplant']);
if (!RequestParamProcessor::validateSubplantId($locationSubplant) && $locationSubplant !== 'all') {
    if (empty($locationSubplant)) {
        $requestErrors['location_subplant'] = 'location_subplant is empty!';
    } else {
        $requestErrors['location_subplant'] = "Unknown location_subplant [$locationSubplant]!";
    }
}

if (count($requestErrors) > 0) {
    HttpUtils::sendError($mode, 'Invalid params!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

if ($locationSubplant !== 'all' && !in_array($locationSubplant, $user->gua_subplants)) {
    $userid = $user->gua_kode;
    $requestErrors = array(
        'location_subplant' => "User $userid tidak bisa mengakses data palet pada kode gudang [$locationSubplant]!"
    );
    HttpUtils::sendError($mode, 'Forbidden!', $requestErrors, HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
}

try {
    $db = PostgresqlDatabase::getInstance();

    $paramCount = 2;
    $queryMotif = count($motifSpecs) > 0 && !empty($motifSpecs) ? 'motif_group_name = ANY($' . $paramCount++ . ')' : '1=1';
    $queryProductionSubplant = null;
    if ($subplant === 'all') {
        $queryProductionSubplant = '1=1';
    } else if ($subplant === 'other') {
        $queryProductionSubplant = 'LEFT(production_subplant, 1) <> $' . $paramCount++;
    } else if ($subplant === 'local') {
        $queryProductionSubplant = '(CASE WHEN production_subplant IN (\'4\', \'5\') THEN production_subplant || \'A\' ELSE production_subplant END) = ANY($' . $paramCount++ . ')';
    } else {
        $queryProductionSubplant = 'production_subplant = $' . $paramCount++;
    }
    $query = "SELECT a.*, b.block_qty
        FROM (
            SELECT location_subplant, production_subplant, motif_group_name, motif_dimension, color, quality, size, shading, is_rimpil, SUM(pallet_count) AS pallet_count, SUM(current_quantity) AS current_quantity
            FROM summary_sku_available_for_sales
            WHERE location_subplant = ANY($1)
            AND $queryMotif
            AND $queryProductionSubplant
            GROUP BY location_subplant, production_subplant, motif_group_name, motif_dimension, color, quality, size, shading, is_rimpil
        ) AS a
        LEFT JOIN (
            SELECT location_subplant, subplant, motif_group_name, motif_dimension, color, quality, size, shading, is_rimpil, SUM(quantity) AS block_qty
            FROM (
                SELECT inv_opname.io_plan_kode AS location_subplant, item_gbj_stockblock.subplant, btrim(regexp_replace(item.spesification, '(ECO|ECONOMY|EKONOMI|ECONOMI|EXP|EXPORT)\s*', '', 'g')) AS motif_group_name, category.category_nama AS motif_dimension, item.color, tbl_sp_hasilbj.size, tbl_sp_hasilbj.shade AS shading, COALESCE(rimpil.is_rimpil, false) AS is_rimpil, item_gbj_stockblock.quantity,
                CASE
                    WHEN item.quality = 'EXPORT' THEN 'EXP'
                    WHEN item.quality = 'ECONOMY' OR item.quality = 'EKONOMI' THEN 'ECO'
                    ELSE item.quality
                END AS quality
                FROM item_gbj_stockblock
                JOIN inv_opname ON (item_gbj_stockblock.pallet_no = inv_opname.io_no_pallet)
                JOIN tbl_sp_hasilbj ON (inv_opname.io_no_pallet = tbl_sp_hasilbj.pallet_no)
                JOIN item ON (tbl_sp_hasilbj.item_kode = item.item_kode)
                JOIN category ON left(tbl_sp_hasilbj.item_kode, 2) = category.category_kode
                LEFT JOIN rimpil_by_motif_size_shading rimpil ON
                    CASE
                        WHEN tbl_sp_hasilbj.subplant = ANY (ARRAY['4', '5']) THEN (tbl_sp_hasilbj.subplant || 'A')
                        ELSE tbl_sp_hasilbj.subplant
                    END = rimpil.production_subplant AND tbl_sp_hasilbj.item_kode = rimpil.motif_id AND tbl_sp_hasilbj.size = rimpil.size AND tbl_sp_hasilbj.shade = rimpil.shading 
                WHERE item_gbj_stockblock.order_status = 'O'
            ) AS z
            GROUP BY location_subplant, subplant, motif_group_name, motif_dimension, color, quality, size, shading, is_rimpil
        ) AS b ON(a.location_subplant = b.location_subplant AND a.production_subplant = b.subplant AND a.motif_group_name = b.motif_group_name AND a.motif_dimension = b.motif_dimension AND a.color = b.color AND a.quality = b.quality AND a.size = b.size AND a.shading = b.shading AND a.is_rimpil = b.is_rimpil)";
    $params = array();

    $params[] = $locationSubplant === 'all' ? $user->gua_subplants : array($locationSubplant);

    if (count($motifSpecs) > 0 && !empty($motifSpecs)) {
        $params[] = $motifSpecs;
    }

    if ($subplant === 'all') {
        // add nothing
    } else if ($subplant === 'other') {
        $params[] = (string) PlantIdHelper::getCurrentPlant();
    } else if ($subplant === 'local') {
        $params[] = $user->gua_subplant_handover;
    } else {
        $params[] = $subplant;
    }

    $cursor = $db->parameterizedQuery($query, $params);
    $summaries = array();
    while ($row = pg_fetch_assoc($cursor)) {
        $row['is_rimpil'] = QueryResultConverter::toBool($row['is_rimpil']);
        $row['current_quantity'] = intval($row['current_quantity']);
        $row['pallet_count'] = intval($row['pallet_count']);
        $row['block_qty'] = intval($row['block_qty']);
        $row['ava_qty'] = intval($row['current_quantity'])-intval($row['block_qty']);
        if($row['quality'] == "KW4") {
            $row['quality'] =  "LOKAL";
        } else if($row['quality'] == "KW5") {
            $row['quality'] =  "BBM SQUARING";
        } else if($row['quality'] == "KW6") {
            $row['quality'] =  "BBM OVERSIZE";
        }
        $summaries[] = $row;
    }

    switch($mode) {
        case HttpUtils::MODE_XML:
            HttpUtils::sendXmlResponse($summaries);
            break;
        case HttpUtils::MODE_JSON:
            HttpUtils::sendJsonResponse($summaries, '', $user->gua_kode);
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
