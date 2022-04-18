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
$queryParams = HttpUtils::getRequestValues(array('motif_specs', 'subplant', 'with_rimpil'), '');
$requestErrors = array();

$motifSpecs = $queryParams['motif_specs'];
if (!is_array($motifSpecs) && $motifSpecs !== '') {
    $requestErrors['motif_specs'] = 'motif_specs should be an array!';
}
if ($motifSpecs === '') {
    $motifSpecs = array();
}

$subplant = $queryParams['subplant'];
if (!is_string($subplant)) {
    $requestErrors['subplant'] = 'Unknown subplant!';
} else if (!RequestParamProcessor::validateSubplantId($subplant) && !in_array($subplant, PlantIdHelper::getAggregateQueryTypes())) {
    if (empty($subplant)) {
        $requestErrors['subplant'] = 'subplant is empty!';
    } else {
        $requestErrors['subplant'] = "Unknown subplant [$subplant]!";
    }
}

$withRimpil = null;
try {
    $withRimpil = $queryParams['with_rimpil'] === '' ? false : RequestParamProcessor::getBoolean($queryParams['with_rimpil']);
} catch (InvalidArgumentException $e) {
    $requestErrors['with_rimpil'] = $e->getMessage();
}

if (count($requestErrors) > 0) {
    HttpUtils::sendError($mode, 'Invalid params!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();

    $paramCount = 2;
    $queryMotif = count($motifSpecs) > 0 && !empty($motifSpecs) ? 'motif_group_name = ANY($' . $paramCount++ . ')' : '1=1';

    $queryProductionSubplant = null;
    if ($subplant === 'all') {
        $queryProductionSubplant = '1=1';
    } else if ($subplant === 'local') {
        $queryProductionSubplant = '(CASE WHEN production_subplant IN (\'4\', \'5\') THEN production_subplant || \'A\' ELSE production_subplant END) = ANY($' . $paramCount++ . ')';
    } else if ($subplant === 'other') {
        $queryProductionSubplant = 'LEFT(production_subplant, 1) <> $' . $paramCount++;
    } else {
        $queryProductionSubplant = '(CASE WHEN production_subplant IN (\'4\', \'5\') THEN production_subplant || \'A\' ELSE production_subplant END) = $' . $paramCount++;
    }

    //====Update Block quantity yang sudah dikirim/dikeluarkan dari warehouse=============================================
    $query2 = 'UPDATE item_gbj_stockblock SET order_status = $1 
               WHERE pallet_no IN (
                    select pallet_no from tbl_sp_hasilbj where pallet_no in (
                        SELECT pallet_no FROM item_gbj_stockblock WHERE order_status = $2
                    ) and last_qty = 0
                )';
    $params2 = array('S', 'O');
    $cursor2 = $db->parameterizedQuery($query2, $params2);

    // $query4 = 'UPDATE item_gbj_stockblock SET quantity = tbl_sp_hasilbj.last_qty 
    //     FROM tbl_sp_hasilbj 
    //     WHERE item_gbj_stockblock.pallet_no = tbl_sp_hasilbj.pallet_no 
    //     AND item_gbj_stockblock.order_status = $1 AND item_gbj_stockblock.quantity <> tbl_sp_hasilbj.last_qty';
    // $params4 = array('O');
    // $cursor4 = $db->parameterizedQuery($query4, $params4);

    $query3 = 'UPDATE tbl_gbj_stockblock SET order_status = $1
               WHERE order_id IN (
                    SELECT a.order_id
                    FROM (
                        SELECT order_id, count(pallet_no) AS jml_a FROM item_gbj_stockblock GROUP BY order_id
                    ) AS a
                    LEFT JOIN (
                        SELECT order_id, count(pallet_no) AS jml_s FROM item_gbj_stockblock WHERE order_status = $2 GROUP BY order_id
                    ) AS b ON (a.order_id = b.order_id)
                    WHERE a.jml_a = b.jml_s
               ) AND order_status = $3';
    $params3 = array('S', 'S', 'O');
    $cursor3 = $db->parameterizedQuery($query3, $params3);
    //=================================================

    $query = null;
    if ($withRimpil) {
        $query = "SELECT a.*, b.block_qty
            FROM (
                SELECT location_subplant, production_subplant, motif_dimension, motif_group_id, motif_group_name, quality, is_rimpil, SUM(pallet_count) AS pallet_count, SUM(quantity) AS quantity
                FROM summary_stock_by_motif_group_with_rimpil 
                WHERE location_subplant = ANY($1)
                AND $queryMotif
                AND $queryProductionSubplant
                and quality NOT IN ('KW4','KW5')
                GROUP BY location_subplant, production_subplant, motif_dimension, motif_group_id, motif_group_name, quality, is_rimpil
            ) AS a
            LEFT JOIN (
                SELECT location_subplant, subplant, motif_dimension, motif_group_id, motif_group_name, quality, is_rimpil, SUM(quantity) AS block_qty
                FROM (
                    SELECT inv_opname.io_plan_kode AS location_subplant, item_gbj_stockblock.subplant, category.category_nama AS motif_dimension, item.category_kode AS motif_group_id, btrim(regexp_replace(item.spesification, '(ECO|ECONOMY|EKONOMI|ECONOMI|EXP|EXPORT)\s*', '', 'g')) AS motif_group_name, COALESCE(rimpil.is_rimpil, false) AS is_rimpil, item_gbj_stockblock.quantity,
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
                GROUP BY location_subplant, subplant, motif_dimension, motif_group_id, motif_group_name, quality, is_rimpil
            ) AS b ON(a.location_subplant = b.location_subplant AND a.production_subplant = b.subplant AND a.motif_dimension = b.motif_dimension AND a.motif_group_id = b.motif_group_id AND a.motif_group_name = b.motif_group_name AND a.quality = b.quality AND a.is_rimpil = b.is_rimpil)";
    } else {
        $query = "SELECT a.*, b.block_qty
            FROM (
                SELECT location_subplant, production_subplant, motif_dimension, motif_group_id, motif_group_name, quality, SUM(pallet_count) AS pallet_count, SUM(quantity) AS quantity
                FROM summary_stock_by_motif_group_with_rimpil
                WHERE location_subplant = ANY($1)
                AND $queryMotif
                AND $queryProductionSubplant
                and quality NOT IN ('KW4','KW5')
                GROUP BY location_subplant, production_subplant, motif_dimension, motif_group_id, motif_group_name, quality
            ) AS a
            LEFT JOIN (
                SELECT location_subplant, subplant, motif_dimension, motif_group_id, motif_group_name, quality, SUM(quantity) AS block_qty
                FROM (
                    SELECT inv_opname.io_plan_kode AS location_subplant, item_gbj_stockblock.subplant, category.category_nama AS motif_dimension, item.category_kode AS motif_group_id, btrim(regexp_replace(item.spesification, '(ECO|ECONOMY|EKONOMI|ECONOMI|EXP|EXPORT)\s*', '', 'g')) AS motif_group_name, item_gbj_stockblock.quantity,
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
                GROUP BY location_subplant, subplant, motif_dimension, motif_group_id, motif_group_name, quality
            ) AS b ON(a.location_subplant = b.location_subplant AND a.production_subplant = b.subplant AND a.motif_dimension = b.motif_dimension AND a.motif_group_id = b.motif_group_id AND a.motif_group_name = b.motif_group_name AND a.quality = b.quality)";
    }
    $params = array($user->gua_subplants);
    if (count($motifSpecs) > 0 && !empty($motifSpecs)) {
        $params[] = $motifSpecs;
    }

    if ($subplant === 'all') {
        // no param added
    } else if ($subplant === 'local') {
        $params[] = $user->gua_subplant_handover;
    } else if ($subplant === 'other') {
        $params[] = (string)PlantIdHelper::getCurrentPlant();
    } else {
        $params[] = $subplant;
    }

    $cursor = $db->parameterizedQuery($query, $params);
    $summaries = array();
    while ($row = pg_fetch_assoc($cursor)) {
        if ($withRimpil) {
            $row['is_rimpil'] = QueryResultConverter::toBool($row['is_rimpil']);
        }
        $row['quantity'] = intval($row['quantity']);
        $row['pallet_count'] = intval($row['pallet_count']);
        $row['block_qty'] = intval($row['block_qty']);
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
