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

$requestErrors = array();
$queryParams = HttpUtils::getRequestValues(array('subplant'));
$subplant = trim($queryParams['subplant']);
if (!RequestParamProcessor::validateSubplantId($subplant) && !in_array($subplant, PlantIdHelper::getAggregateQueryTypes())) {
    if (empty($subplant)) {
        $requestErrors['subplant'] = 'subplant is empty!';
    } else {
        $requestErrors['subplant'] = "Unknown subplant [$subplant]!";
    }
}

if (count($requestErrors) > 0) {
    HttpUtils::sendError($mode, 'Invalid params!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $db = PostgresqlDatabase::getInstance();

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

    $queryProductionSubplant = null;
    if ($subplant === 'all') {
        $queryProductionSubplant = '';
    } else if ($subplant === 'other') {
        $queryProductionSubplant = ' AND LEFT(production_subplant, 1) <> $2';
    } else if ($subplant === 'local') {
        $queryProductionSubplant = ' AND (CASE WHEN production_subplant IN (\'4\', \'5\') THEN production_subplant || \'A\' ELSE production_subplant END) = ANY($2)';
    } else {
        $queryProductionSubplant = ' AND (CASE WHEN production_subplant IN (\'4\', \'5\') THEN production_subplant || \'A\' ELSE production_subplant END) = $2';
    }

    // $query = " SELECT production_subplant, quality, motif_dimension, motif_id, motif_name, SUM(pallet_count) AS pallet_count, SUM(total_quantity) AS total_quantity 
    //     FROM summary_stock_by_motif_location 
    //     WHERE total_quantity > 0 AND location_warehouse_id = ANY($1) $queryProductionSubplant
    //     GROUP BY production_subplant, quality, motif_dimension, motif_id, motif_name
    //     ORDER BY production_subplant, quality, motif_dimension, motif_name";

    $query = "SELECT a.*, b.block_qty
    FROM (
        SELECT production_subplant, quality, motif_dimension, motif_id, motif_name, SUM(pallet_count) AS pallet_count, SUM(total_quantity) AS total_quantity 
        FROM summary_stock_by_motif_location 
        WHERE total_quantity > 0 AND location_warehouse_id = ANY($1) $queryProductionSubplant
        GROUP BY production_subplant, quality, motif_dimension, motif_id, motif_name
    ) AS a
    LEFT JOIN (
        SELECT subplant, quality, motif_id, motif_name, SUM(quantity) AS block_qty 
        FROM (
            SELECT item_gbj_stockblock.subplant, item.item_kode AS motif_id, item.item_nama AS motif_name, tbl_sp_hasilbj.size, tbl_sp_hasilbj.shade AS shading, item_gbj_stockblock.quantity, item_gbj_stockblock.order_id, item_gbj_stockblock.pallet_no, 
            CASE
                WHEN item.quality = 'EXPORT' THEN 'EXP'
                WHEN item.quality = 'ECONOMY' OR item.quality = 'EKONOMI' THEN 'ECO'
                ELSE item.quality
            END AS quality
            FROM item_gbj_stockblock
            JOIN tbl_sp_hasilbj ON (tbl_sp_hasilbj.pallet_no = item_gbj_stockblock.pallet_no)
            JOIN item ON (tbl_sp_hasilbj.item_kode = item.item_kode)
            WHERE item_gbj_stockblock.order_status = 'O'
        ) AS z
        GROUP BY subplant, quality, motif_id, motif_name
    ) AS b ON(a.production_subplant = b.subplant AND a.quality = b.quality AND a.motif_id = b.motif_id AND a.motif_name = b.motif_name)
    ORDER BY a.production_subplant, a.quality, a.motif_dimension, a.motif_name";

    $locationSubplants = $user->gua_subplants;
    if (PlantIdHelper::usesLocationCell()) {
        $locationSubplants = array_map(function ($subplant) {
            return PlantIdHelper::toSubplantId($subplant);
        }, $locationSubplants);
    }
    $params[] = $locationSubplants;

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
        $row['total_quantity'] = intval($row['total_quantity']);
        $row['pallet_count'] = intval($row['pallet_count']);
        $row['block_qty'] = intval($row['block_qty']);
        $row['ava_qty'] = intval($row['total_quantity'])-intval($row['block_qty']);
        if($row['quality'] == "KW4") {
            $row['quality'] =  "LOKAL";
            $row['motif_name'] = str_replace("KW4","LOKAL",$row['motif_name']);
        } else if($row['quality'] == "KW5") {
            $row['quality'] =  "BBM SQUARING";
            $row['motif_name'] = str_replace("KW5","BBM SQUARING",$row['motif_name']);
        } else if($row['quality'] == "KW6") {
            $row['quality'] =  "BBM OVERSIZE";
            $row['motif_name'] = str_replace("KW6","BBM OVERSIZE",$row['motif_name']);
        }
        $summaries[] = $row;
    }

    switch ($mode) {
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
