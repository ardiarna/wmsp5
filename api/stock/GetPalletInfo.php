<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

use Utils\Env;

SessionUtils::sessionStart();

if (!SessionUtils::isAuthenticated()) {
    HttpUtils::sendError('json', 'Belum terautentikasi!', array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}
$userid = SessionUtils::getUser()->gua_kode;
$requests = HttpUtils::getRequestValues(array('pallet_no'));

if (empty($requests['pallet_no'])) {
	HttpUtils::sendJsonResponse(array('pallet_no' => 'Nomor palet kosong!'), 'Nomor palet kosong!', $userid,
        HttpUtils::STATUS_BAD_REQUEST,
        HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
	exit;
}

try {
	$palletNo = $requests['pallet_no'];

	$db = PostgresqlDatabase::getInstance();
	// TODO filter out result based on userid.
    $query = 'SELECT
  -- item identity, minus pallet no.
  item_master.item_nama                                                          AS motif_name,
  item_master.item_kode                                                          AS motif_no,
  (SELECT category_nama
   FROM category
   WHERE category.category_kode = substr(hasilbj.item_kode, 1, 2)) AS motif_dimension,

  hasilbj.qty                                                      AS initial_quantity,
  hasilbj.last_qty                                                 AS current_quantity,
  hasilbj.quality                                                  AS quality,
  hasilbj.shade                                                    AS shading,
  hasilbj.size                                                     AS size,
  hasilbj.line                                                     AS line,

  hasilbj.shift                                                    AS creator_shift,
  hasilbj.regu                                                     AS creator_group,

  hasilbj.status_plt                                               AS status,

  -- marked for handover
  hasilbj.rkpterima_tanggal                                        AS marked_for_handover_date,
  hasilbj.rkpterima_no                                             AS marked_for_handover_ref_no,
  hasilbj.rkpterima_user                                           AS marked_for_handover_user,

  -- sent to warehouse
  hasilbj.terima_no                                                AS stwh_ref_no,
  hasilbj.tanggal_terima                                           AS stwh_date,
  hasilbj.terima_user                                              AS stwh_user,

  hasilbj.create_date                                              AS created_at,
  (CASE
    WHEN hasilbj.update_tran IS NOT NULL THEN hasilbj.update_tran
    WHEN mutation.last_updated_at IS NOT NULL THEN mutation.last_updated_at
     -- use default values based on the start time of every shift
    WHEN hasilbj.shift = 1 THEN (hasilbj.create_date + time \'07:00:00\')
    WHEN hasilbj.shift = 2 THEN (hasilbj.create_date + time \'15:00:00\')
    WHEN hasilbj.shift = 3 THEN (hasilbj.create_date + time \'23:00:00\')
    ELSE (hasilbj.create_date + time \'00:00:00\')
 END)                                                              AS updated_at,
  hasilbj.qa_approved                                              AS qa_approved,
  
  COALESCE(sold_quantity + foc_quantity + sample_quantity, 0)      AS shipped_quantity,
  COALESCE(ABS(in_mut_quantity - out_mut_quantity 
  + in_adjusted_quantity - out_adjusted_quantity 
  - returned_quantity), 0)                                         AS adjusted_quantity,
  -- location
  io.io_kd_lok                                                     AS location_id,
  io.io_plan_kode                                                  AS location_subplant,
  iml.iml_no_lok                                                   AS location_line_no,
  iml.iml_kd_area                                                  AS location_area_no,
  ima.ket_area                                                     AS location_area_name
FROM tbl_sp_hasilbj hasilbj
  LEFT JOIN pallets_mutation_summary_by_quantity mutation ON hasilbj.pallet_no = mutation.pallet_no
  LEFT JOIN inv_opname io ON hasilbj.pallet_no = io.io_no_pallet
  LEFT JOIN item item_master ON hasilbj.item_kode = item_master.item_kode
  LEFT JOIN inv_master_lok_pallet iml on io.io_plan_kode = iml.iml_plan_kode and io.io_kd_lok = iml.iml_kd_lok
  LEFT JOIN inv_master_area ima on iml.iml_plan_kode = ima.plan_kode and iml.iml_kd_area = ima.kd_area
WHERE hasilbj.pallet_no = $1';
	$res = $db->parameterizedQuery($query, array($palletNo));
	$rowCount = pg_num_rows($res);
	$queryResult = pg_fetch_object($res);

	if (!$queryResult || $rowCount === 0) {
        $db->close();
		HttpUtils::sendJsonResponse(array('pallet_no' => "Pallet dengan kode $palletNo tidak ditemukan."),
            "Kode palet tidak ditemukan.",
            $userid,
            HttpUtils::STATUS_NOT_FOUND,
            HttpUtils::HTTP_RESPONSE_NOT_FOUND);
		exit;
	}

	$response = array(
	    'pallet_no' => $palletNo,

		// for older binaries
		'location_no' => $queryResult->location_no,

		// for newer binaries
		'location' => isset($queryResult->location_no) ? array(
            'subplant' => PlantIdHelper::toSubplantId($queryResult->location_subplant),
		    'no' => $queryResult->location_no,
            'name' => $queryResult->location_name,
            'area_code' => $queryResult->location_area_code,
            'row_no' => $queryResult->location_row_no
        ) : null,

		'motif' => array(
		    'id' => $queryResult->motif_no,
            'name' => $queryResult->motif_name,
            'dimension' => $queryResult->motif_dimension
        ),
		'production_quantity' => intval($queryResult->initial_quantity),
		'current_quantity' => intval($queryResult->current_quantity),
		'quality' => $queryResult->quality,
		'shading' => $queryResult->shading,
		'size' => $queryResult->size,
        'line' => intval($queryResult->line),
        'status' => $queryResult->pallet_status,

        'creator_group' => $queryResult->creator_group,
        'creator_shift' => $queryResult->creator_shift,

        'created_at' => $queryResult->created_at,
        'updated_at' => $queryResult->updated_at,

        'qa_approved' => $queryResult->qa_approved === PostgresqlDatabase::PGSQL_TRUE
	);
	if (is_null($queryResult->updated_at)) {
	    $updatedAtTimestamp = new DateTime($queryResult->created_at);
	    switch($response['creator_shift']) {
            case 1:
                $updatedAtTimestamp->setTime(7, 0);
                break;
            case 2:
                $updatedAtTimestamp->setTime(15, 0);
                break;
            case 3:
                $updatedAtTimestamp->setTime(23, 0);
                break;
        }
	    $response['updated_at'] = $updatedAtTimestamp->format(PostgresqlDatabase::PGSQL_TIMESTAMP_FORMAT);
    }

	if (!is_null($queryResult->stwh_ref_no)) {
	    $response['stwh'] = array(
	        'no' => $queryResult->stwh_ref_no,
            'userid' => $queryResult->stwh_user,
            'date' => $queryResult->stwh_date
        );
    } else {
        $response['stwh'] = null;
    }
    if (!is_null($queryResult->marked_for_handover_ref_no)) {
        $response['mark_for_handover'] = array(
            'no' => $queryResult->marked_for_handover_ref_no,
            'userid' => $queryResult->marked_for_handover_user,
            'date' => $queryResult->marked_for_handover_date
        );
    } else {
        $response['mark_for_handover'] = null;
    }

    $db->close();

	HttpUtils::sendJsonResponse($response, $userid);
} catch (PostgresqlDatabaseException $e) {
	HttpUtils::sendError('json', (string) $e,
        Env::isDebug() ? $e->getTrace() : array()
    );
}
