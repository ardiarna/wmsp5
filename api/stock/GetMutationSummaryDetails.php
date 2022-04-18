<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

use Security\RoleAcl;
use Utils\Env;

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
if (empty($user->gua_subplant_handover) || !UserRole::hasAnyRole(RoleAcl::mutationReport())) {
    $errorMessage = 'You are not authorized to access any mutation data!';
    HttpUtils::sendError($mode, $errorMessage, array(), HttpUtils::HTTP_RESPONSE_FORBIDDEN);
    exit;
}

const MUTATION_TYPE_BEGIN = 'BEGIN';
const MUTATION_TYPE_PROD = 'PROD';
const MUTATION_TYPE_MANUAL = 'PLM';
const MUTATION_TYPE_MUTATION_IN = 'MUT_IN';
const MUTATION_TYPE_ADJUSTMENT_IN = 'ADJ_IN';
const MUTATION_TYPE_DOWNGRADE_IN = 'DWG_IN';
const MUTATION_TYPE_TOTAL_IN = 'TTL_IN';

const MUTATION_TYPE_MUTATION_OUT = 'MUT_OUT';
const MUTATION_TYPE_ADJUSTMENT_OUT = 'ADJ_OUT';
const MUTATION_TYPE_PRODUCTION_RETURNED = 'PROD_RET';
const MUTATION_TYPE_BROKEN = 'BROKEN';
const MUTATION_TYPE_SALES_IN_PROGRESS = 'SALES_IN_PROGRESS';
const MUTATION_TYPE_SALES_IN_PROGRESS_PALLET = 'SALES_IN_PROGRESS_PALLET';
const MUTATION_TYPE_SALES_CONFIRMED = 'SALES_CONFIRMED';
const MUTATION_TYPE_SALES_CONFIRMED_PALLET = 'SALES_CONFIRMED_PALLET';
const MUTATION_TYPE_FOC = 'FOC';
const MUTATION_TYPE_SMP = 'SMP';
const MUTATION_TYPE_DOWNGRADE_OUT = 'DWG_OUT';
const MUTATION_TYPE_TOTAL_OUT = 'TTL_OUT';
const MUTATION_TYPE_END = 'END';

$availableMutationTypes = array(
    // show pallet no, size, shading, qty before mutation date
    MUTATION_TYPE_BEGIN,

    // show mutation id, pallet no., time, and user.
    MUTATION_TYPE_PROD, MUTATION_TYPE_MANUAL, MUTATION_TYPE_MUTATION_IN,
    MUTATION_TYPE_ADJUSTMENT_IN, MUTATION_TYPE_DOWNGRADE_IN,

    // show mutation type, mutation id, pallet no., and time, order by time.
    MUTATION_TYPE_TOTAL_IN,

    // show mutation id, pallet no., time, and user.
    MUTATION_TYPE_MUTATION_OUT, MUTATION_TYPE_ADJUSTMENT_OUT, MUTATION_TYPE_PRODUCTION_RETURNED, MUTATION_TYPE_BROKEN,
    MUTATION_TYPE_SALES_IN_PROGRESS, MUTATION_TYPE_SALES_IN_PROGRESS_PALLET, MUTATION_TYPE_SALES_CONFIRMED, MUTATION_TYPE_SALES_CONFIRMED_PALLET,
    MUTATION_TYPE_FOC, MUTATION_TYPE_SMP, MUTATION_TYPE_DOWNGRADE_OUT,

    // show mutation type, mutation id, pallet no., and time, order by time.
    MUTATION_TYPE_TOTAL_OUT,

    // show pallet no., size, shading, qty after mutation date, and current location
    MUTATION_TYPE_END
);

// get params
/*
 * - subplant: subplant to check (pallet source)
 * - motif_id: motif to check (pallet motif)
 * - mutation_type: predefined mutation details to be checked against.
 * - date_from: start of mutation
 * - date_to: end of mutation */
$requests = HttpUtils::getRequestValues(array('subplant', 'motif_id', 'mutation_type', 'date_from', 'date_to'));
$requestErrors = array();

$mutationType = $requests['mutation_type'];
if (empty($mutationType)) {
    $requestErrors['mutation_type'] = 'mutation_type is empty!';
} else if (!in_array($mutationType, $availableMutationTypes)) {
    $requestErrors['mutation_type'] = "Unknown mutation_type $mutationType!";
}

$subplant = $requests['subplant'];
$subplants = array();
if (is_array($subplant)) {
    $subpErrors = array();
    foreach ($subplant as $subp) {
        if (!in_array($subp, $user->gua_subplant_handover, true)) {
            $subpErrors[] = $subp;
        }
    }
    if (!empty($subpErrors)) {
        $requestErrors['subplant'] = 'Unknown/invalid/unauthorized subplant(s) (' . implode(', ', $subpErrors) . ')';
    } else {
        $subplants = $subplant;
    }
} elseif (!in_array($subplant, $user->gua_subplant_handover, true)) {
    $requestErrors['subplant'] = "Unknown subplant $subplant!";
} else {
    $subplants[] = $subplant;
}

$motifIds = array();
$motifId = $requests['motif_id'];
if (empty($motifId)) {
    $requestErrors['motif_id'] = "motif_id is empty!";
} elseif (is_string($motifId)) {
    $motifIds[] = $motifId;
} elseif (is_array($motifId)) {
    $motifIds = $motifId;
} else {
    $requestErrors['motif_id'] = 'unknown type for motif_id ' . gettype($motifId);
}

$dateFrom = $requests['date_from'];
if (empty($dateFrom)) {
    $requestErrors['date_from'] = "date_from is empty!";
}
try {
    $dt_dateFrom = DateTime::createFromFormat('Y-m-d', $dateFrom);
    if (!$dt_dateFrom) throw new Exception('Invalid date for date_from: ' . $dateFrom);
} catch (Exception $e) {
    $requestErrors['date_from'] = $e->getMessage();
}

$dateTo = $requests['date_to'];
if (empty($dateTo)) {
    $requestErrors['date_to'] = "date_to is empty!";
}
try {
    $dt_dateTo = DateTime::createFromFormat('Y-m-d', $dateTo);
    if (!$dt_dateTo) throw new Exception('Invalid date for date_to: ' . $dateTo);
} catch (Exception $e) {
    $requestErrors['date_to'] = $e->getMessage();
}
if (!empty($requestErrors)) {
    HttpUtils::sendError($mode, 'Ada kesalahan pada permintaan data!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

function generate_details_query($mutationType)
{
    $query = null;
    if ($mutationType === MUTATION_TYPE_BEGIN) {
        $query = "
            SELECT a.*,
                   quality,
                   motif_id,
                   item_nama AS motif_name,
                   category_nama AS motif_dimension
            FROM (SELECT DISTINCT ON (pallet_no) pallet_no,
                   subplant,
                   motif_id,
                   LAST_VALUE(size) OVER w AS size,
                   LAST_VALUE(shading) OVER w AS shading,
                   SUM(quantity) OVER (PARTITION BY pallet_no) AS quantity
                  FROM gbj_report.mutation_records_adjusted
                  WHERE subplant = ANY($1)
                    AND motif_id = ANY($2)
                    AND mutation_time <= $3
                  WINDOW w AS (PARTITION BY pallet_no ORDER BY mutation_time)
            ) a
            JOIN item ON item_kode = motif_id
            JOIN category cat ON LEFT(item.item_kode, 2) = cat.category_kode
            WHERE quantity <> 0 -- for checking minus mutations.
            ORDER BY subplant, pallet_no
        ";
    } else if ($mutationType === MUTATION_TYPE_END) {
        $query = "
            SELECT a.*,
                   io_kd_lok AS location_id,
                   quality,
                   motif_id,
                   item_nama AS motif_name,
                   category_nama AS motif_dimension
            FROM (SELECT DISTINCT ON (pallet_no) pallet_no,
                   subplant,
                   motif_id,
                   LAST_VALUE(size) OVER w AS size,
                   LAST_VALUE(shading) OVER w AS shading,
                   SUM(quantity) OVER (PARTITION BY pallet_no) AS quantity
                  FROM gbj_report.mutation_records_adjusted
                  WHERE subplant = ANY($1)
                    AND motif_id = ANY($2)
                    AND mutation_time <= $3
                  WINDOW w AS (PARTITION BY pallet_no ORDER BY mutation_time)
            ) a
            JOIN item ON item_kode = motif_id
            JOIN category cat ON LEFT(item.item_kode, 2) = cat.category_kode
            LEFT JOIN inv_opname ON pallet_no = io_no_pallet
            WHERE quantity <> 0 -- for checking minus mutations.
            ORDER BY subplant, pallet_no
        ";
    } else if ($mutationType === MUTATION_TYPE_TOTAL_IN) {
        $query = '
            SELECT mutation_id, mutation_time, mutation_type,
                   motif_id, quality, item_nama AS motif_name, category_nama AS motif_dimension,
                   pallet_no, subplant, size, shading, quantity
            FROM gbj_report.mutation_records_adjusted
            JOIN item ON item_kode = motif_id
            JOIN category cat ON LEFT(item.item_kode, 2) = cat.category_kode
            WHERE subplant = ANY($1)
              AND motif_id = ANY($2)
              AND mutation_time BETWEEN $3 AND $4
              AND quantity > 0
            ORDER BY mutation_time, pallet_no
        ';
    } else if ($mutationType === MUTATION_TYPE_TOTAL_OUT) {
        // sales: show as summary
        // others: show details
        $query = "
            SELECT a.*,
                   quality,
                   item_nama AS motif_name,
                   category_nama AS motif_dimension
            FROM (
                SELECT mutation_id, mutation_time, mutation_type, motif_id, pallet_no, subplant, size, shading, ABS(quantity) AS quantity
                FROM gbj_report.mutation_records_adjusted
                WHERE subplant = ANY($1)
                  AND motif_id = ANY($2)
                  AND mutation_time >= $3 
                  AND mutation_time < $4
                  AND quantity < 0
                  AND mutation_type NOT IN ('BAM', 'BAL', 'JSP', 'JSR')
                UNION ALL
                SELECT COALESCE(ref_txn_id, mutation_id) AS mutation_id, 
                       mutation_time, mutation_type,
                       motif_id,
                       'SUMMARY', 
                       subplant, 
                       '', -- size
                       '', -- shading
                       SUM(ABS(quantity)) AS quantity
                FROM gbj_report.mutation_records_adjusted
                WHERE subplant = ANY($1)
                  AND motif_id = ANY($2)
                  AND mutation_time >= $3
                  AND mutation_time < $4
                  AND mutation_type IN ('BAM', 'BAL', 'JSP', 'JSR')
                GROUP BY COALESCE(ref_txn_id, mutation_id), mutation_time, subplant, motif_id, mutation_type
            ) a
            JOIN item ON item_kode = motif_id
            JOIN category cat ON LEFT(item.item_kode, 2) = cat.category_kode
            ORDER BY mutation_time, pallet_no
        ";
    } else if ($mutationType === MUTATION_TYPE_SALES_IN_PROGRESS) {
        $query = "
                SELECT
                    mutation_id,
                    mutation_time :: DATE AS mutation_date,
                    mutation_type,
                    subplant,
                    motif_id,
                    quality,
                    item_nama AS motif_name,
                    category_nama AS motif_dimension,
                    SUM(ABS(quantity)) AS quantity   
                FROM gbj_report.mutation_records_adjusted t1
                JOIN item ON t1.motif_id = item.item_kode
                JOIN category cat ON LEFT(item.item_kode, 2) = cat.category_kode
                WHERE subplant = ANY($1)
                  AND motif_id = ANY($2)
                  AND mutation_time BETWEEN $3 AND $4
                  AND mutation_type IN ('BAM', 'BAL')
                  AND COALESCE(t1.ref_txn_id, '') = ''
                GROUP BY mutation_id, mutation_date, mutation_type, subplant, motif_id, quality, motif_name, motif_dimension
                ORDER BY mutation_date
            ";
    } else if ($mutationType === MUTATION_TYPE_SALES_IN_PROGRESS_PALLET) {
        $query = "
            SELECT
                mutation_id,
                pallet_no,
                mutation_time::DATE AS mutation_date,
                mutation_type,
                subplant,
                motif_id,
                quality,
                item_nama AS motif_name,
                category_nama AS motif_dimension,
                size,
                shading,
                SUM(ABS(quantity)) AS quantity
            FROM gbj_report.mutation_records_adjusted mut
            JOIN item ON mut.motif_id = item.item_kode
            JOIN category cat ON LEFT(item.item_kode, 2) = cat.category_kode
            WHERE subplant = ANY($1)
              AND motif_id = ANY($2)
              AND mutation_time >= $3 
              AND mutation_time < $4
              AND mutation_type IN ('BAM', 'BAL')
              AND COALESCE(mut.ref_txn_id, '') = ''
            GROUP BY mutation_id, pallet_no, mutation_date, mutation_type, subplant, motif_id, quality, item_nama, category_nama, size, shading
            ORDER BY mutation_date, mutation_id, pallet_no
        ";
    } else if ($mutationType === MUTATION_TYPE_SALES_CONFIRMED) {
        $query = "
                SELECT
                    mutation_id,
                    ref_txn_id,
                    mutation_time :: DATE AS mutation_date,
                    mutation_type,
                    subplant,
                    motif_id,
                    quality,
                    item_nama AS motif_name,
                    category_nama AS motif_dimension,
                    SUM(ABS(quantity)) AS quantity
                FROM gbj_report.mutation_records_adjusted t1
                JOIN item ON t1.motif_id = item.item_kode
                JOIN category cat ON LEFT(item.item_kode, 2) = cat.category_kode
                WHERE subplant = ANY($1)
                  AND motif_id = ANY($2)
                  AND mutation_time BETWEEN $3 AND $4
                  AND mutation_type IN ('BAM', 'BAL', 'JSP', 'JSR')
                  AND ref_txn_id IS NOT NULL
                GROUP BY mutation_id, ref_txn_id, mutation_date, mutation_type, subplant, motif_id, quality, motif_name, motif_dimension
                ORDER BY mutation_date
            ";
    } else if ($mutationType === MUTATION_TYPE_SALES_CONFIRMED_PALLET) {
        $query = "
            SELECT
                mutation_id,
                ref_txn_id,
                pallet_no,
                mutation_time::DATE AS mutation_date,
                mutation_type,
                subplant,
                motif_id,
                quality,
                item_nama AS motif_name,
                category_nama AS motif_dimension,
                size,
                shading,
                cs.customer_nama AS tokoa,
                cb.customer_nama AS tokob, 
                SUM(ABS(quantity)) AS quantity
            FROM gbj_report.mutation_records_adjusted mut
            JOIN item ON mut.motif_id = item.item_kode
            JOIN category cat ON LEFT(item.item_kode, 2) = cat.category_kode
            LEFT JOIN tbl_surat_jalan sj ON mut.ref_txn_id = sj.no_surat_jalan
            LEFT JOIN tbl_customer cs ON sj.customer_kode = cs.customer_kode
            LEFT JOIN tbl_customer cb ON sj.tujuan_surat_jalan_rekap = cb.customer_kode
            WHERE subplant = ANY($1)
              AND motif_id = ANY($2)
              AND mutation_time >= $3 
              AND mutation_time < $4
              AND mutation_type IN ('BAM', 'BAL', 'JSP', 'JSR')
              AND COALESCE(mut.ref_txn_id, '') <> ''
            GROUP BY mutation_id, ref_txn_id, pallet_no, mutation_date, mutation_type, subplant, motif_id, quality, item_nama, category_nama, size, shading, cs.customer_nama, cb.customer_nama
            ORDER BY mutation_date, mutation_id, pallet_no
        ";
    } else if ($mutationType === MUTATION_TYPE_BROKEN) {
        $query = '
            SELECT
                mutation_id,
                pallet_no,
                mutation_time,
                COALESCE(jenis_bahan, mut.mutation_type) AS mutation_type,
                subplant,
                motif_id,
                quality,
                item_nama AS motif_name,
                category_nama AS motif_dimension,
                size,
                shading,
                ABS(quantity) AS quantity
            FROM gbj_report.mutation_records_adjusted mut
            JOIN item ON mut.motif_id = item.item_kode
            JOIN category cat ON LEFT(item.item_kode, 2) = cat.category_kode
            LEFT JOIN tbl_retur_produksi ret ON mut.mutation_id = ret.retur_kode
            WHERE subplant = ANY($1)
              AND motif_id = ANY($2)
              AND mutation_time >= $3 
              AND mutation_time < $4
              AND mutation_type IN (\'BRP\', \'PBP\', \'BRR\' /*KW4 treated as broken*/)
            ORDER BY mutation_time, pallet_no
            ';
    } else {
        $query = '
            SELECT
                mutation_id,
                pallet_no,
                mutation_time,
                mutation_type,
                subplant,
                motif_id,
                quality,
                item_nama AS motif_name,
                category_nama AS motif_dimension,
                size,
                shading,
                ABS(quantity) AS quantity
            FROM gbj_report.mutation_records_adjusted mut
            JOIN item ON mut.motif_id = item.item_kode
            JOIN category cat ON LEFT(item.item_kode, 2) = cat.category_kode
            WHERE subplant = ANY($1)
              AND motif_id = ANY($2)
              AND mutation_time >= $3 
              AND mutation_time < $4
              AND mutation_type = ANY($5)
            ORDER BY mutation_time, pallet_no
            ';
    }

    return $query;
}

try {
    $db = PostgresqlDatabase::getInstance();

    $query = generate_details_query($mutationType);
    $params = array($subplants, $motifIds);

    if ($mutationType === MUTATION_TYPE_BEGIN) {
        $params[] = $dateFrom;
    } else if ($mutationType === MUTATION_TYPE_END) {
        $dt_dateTo->add(DateInterval::createFromDateString('1 day'));
        $params[] = $dt_dateTo->format('Y-m-d');
    } else {
        $params[] = $dateFrom;
        $dt_dateTo->add(DateInterval::createFromDateString('1 day'));
        $params[] = $dt_dateTo->format('Y-m-d');

        if ($mutationType !== MUTATION_TYPE_SALES_IN_PROGRESS || $mutationType !== MUTATION_TYPE_SALES_CONFIRMED) {
            $mutIds = array();
            switch ($mutationType) {
                case MUTATION_TYPE_DOWNGRADE_OUT:
                    $mutIds[] = 'DGO';
                    break;
                case MUTATION_TYPE_DOWNGRADE_IN:
                    $mutIds[] = 'DGI';
                    break;
                case MUTATION_TYPE_PROD:
                    $mutIds[] = 'MBJ';
                    break;
                case MUTATION_TYPE_MUTATION_IN:
                case MUTATION_TYPE_MUTATION_OUT:
                    $mutIds[] = 'MLT';
                    break;
                case MUTATION_TYPE_ADJUSTMENT_IN:
                case MUTATION_TYPE_ADJUSTMENT_OUT:
                    $mutIds[] = 'ADJ'; // manual adjustment
                    $mutIds[] = 'OBJ'; // manual adjustment
                    $mutIds[] = 'OPN'; // manual adjustment
                    $mutIds[] = 'CNC'; // cancellations
                    break;
                case MUTATION_TYPE_PRODUCTION_RETURNED:
                    $mutIds[] = 'ULT';
                    break;
                case MUTATION_TYPE_MANUAL:
                    $mutIds[] = 'SEQ';
                    break;
                case MUTATION_TYPE_SMP:
                    $mutIds[] = 'SMP';
                    break;
                case MUTATION_TYPE_FOC:
                    $mutIds[] = 'FOC';
                    break;
            }
            if (!empty($mutIds)) {
                $params[] = $mutIds;
            }
        }
    }

    $cursor = $db->parameterizedQuery($query, $params);
    $motifs = array();
    $records = array();
    while ($row = pg_fetch_assoc($cursor)) {
        if (isset($row['quantity'])) {
            $row['quantity'] = intval($row['quantity']);
        }
        // optimization: split the motif to a separate 'dictionary'
        if (!isset($motifs[$row['motif_id']])) {
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
            $motifs[$row['motif_id']] = array(
                'motif_name' => $row['motif_name'],
                'motif_dimension' => $row['motif_dimension'],
                'quality' => $row['quality']
            );
        }
        unset($row['motif_name']);
        unset($row['motif_dimension']);
        unset($row['quality']);

        if ($mutationType === MUTATION_TYPE_SALES_CONFIRMED_PALLET) {
          // $query2 = "SELECT b.iml_kd_area ||' - '|| c.ket_area AS area, b.iml_no_lok AS baris 
          //   FROM inv_opname_hist a
          //   JOIN inv_master_lok_pallet b ON (a.ioh_kd_lok_old = b.iml_kd_lok AND a.ioh_plan_kode = b.iml_plan_kode)
          //   JOIN inv_master_area c ON (b.iml_kd_area = c.kd_area AND b.iml_plan_kode = c.plan_kode) 
          //   WHERE a.ioh_no_pallet = $1
          //   ORDER BY a.ioh_tgl DESC LIMIT 1";

          // $res2 = $db->parameterizedQuery($query2, array($row['pallet_no']));
          // $r2 = pg_fetch_assoc($res2);
          // $row['lokasi'] = $r2['area'];
          if($row['mutation_type'] == 'BAL' || $row['mutation_type'] == 'JSP') {
            $row['toko'] = $row['tokoa'];
          } else {
            $row['toko'] = $row['tokob'];
          }      
        }
        //====================================================================================
        
        $records[] = $row;
    }
    $db->close();

    switch ($mode) {
        case HttpUtils::MODE_XML:
            HttpUtils::sendXmlResponse(array(
                'records' => $records,
                'motifs' => $motifs
            ));
            break;
        case HttpUtils::MODE_JSON:
            HttpUtils::sendJsonResponse(array(
                'records' => $records,
                'motifs' => $motifs
            ), '', $user->gua_kode);
            break;
    }
} catch (PostgresqlDatabaseException $e) {
    $errorMessage = $e->getMessage();
    $additionalInfo = array(
        'query' => $e->getQuery(),
        'db_message' => $e->getOriginalMessage()
    );
    HttpUtils::sendError($mode, $errorMessage, Env::isDebug() ? $additionalInfo : '');
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    HttpUtils::sendError($mode, $errorMessage);
}
