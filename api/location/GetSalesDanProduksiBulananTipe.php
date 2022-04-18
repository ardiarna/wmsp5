<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

use Security\RoleAcl;
use Utils\Env;
use Model\PalletDowngrade;

SessionUtils::sessionStart();

if (!SessionUtils::isAuthenticated()) {
    HttpUtils::sendError('json', 'Belum terautentikasi!', array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}
$user = SessionUtils::getUser();

// authorize
$authorized = !empty($user->gua_subplant_handover);
if ($authorized) {
    // check role
    // for now only allow kabag and above to see the data.
    $allowedRoles = RoleAcl::blockQuantity();
    $authorized = UserRole::hasAnyRole($allowedRoles);
}

if (!$authorized) {
    $errorMessage = 'You are not authorized to access block quantity data!';
    HttpUtils::sendError($mode, $errorMessage, array(), HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
    exit;
}

$requests = HttpUtils::getRequestValues(array('subplant', 'tiperpt', 'tahunrpt', 'gruprpt'));
$requestErrors = array();

// validate request
$subplant = trim($requests['subplant']);
$tiperpt = trim($requests['tiperpt']);
$tahunrpt = trim($requests['tahunrpt']);
$gruprpt = trim($requests['gruprpt']);

if (empty($subplant)) {
    $requestErrors['subplant'] = 'subplant kosong!';
} elseif (!RequestParamProcessor::validateSubplantId($subplant) && $subplant !== 'all') {
    $requestErrors['subplant'] = "subplant $subplant tidak dikenal!";
}

if (!empty($requestErrors)) {
    HttpUtils::sendError('json', 'Kesalahan pada permintaan data!', $requestErrors, HttpUtils::HTTP_RESPONSE_BAD_REQUEST);
    exit;
}

try {
    $params = array();
    $params3 = array();
    if ($subplant === 'all') {
        $params[] = $user->gua_subplant_handover;
        $params3[] = $user->gua_subplant_handover;
    } else {
        $params[] = array($subplant);
        $params3[] = array($subplant);
    }

    $params[] = $tahunrpt;

    $db = PostgresqlDatabase::getInstance();
    $row = array();
    $response = array();
    $arr_ukuran = array('05' => '20 X 20', '06' => '30 X 30', '07' => '40 X 40', '08' => '20 X 25', '09' => '25 X 40', '10' => '25 X 25', '11' => '50 X 50', '12' => '25 X 50', '13' => '60 X 60');
    $arr_quality = array('01' => 'EXP', '02' => 'ECO', '03' => 'LOCAL', '04' => 'KW4', '05' => 'BBM SQ', '06' => 'BBM OS');

    $grantotprod = 0;
    $grantotsale = 0;
    if($gruprpt == 'Q') {
        $query = "SELECT a.item_kode, a.subplant, a.quality, a.group_nama, a.bulan, SUM(a.qty) as qty
        FROM (
            SELECT substring(sh.item_kode from 1 for 2) AS item_kode, i.group_nama, sh.subplant, date_part('month', sh.tanggal) as bulan, sh.qty,
            CASE
              WHEN sh.quality = 'EXPORT' THEN '01'
              WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
              WHEN sh.quality = 'LOKAL' THEN '03'
              WHEN sh.quality = 'KW4' THEN '04'
              WHEN sh.quality = 'KW5' THEN '05'
              WHEN sh.quality = 'KW6' THEN '06'
            END AS quality
            FROM tbl_sp_hasilbj sh
            JOIN item i on (i.item_kode = sh.item_kode)
            WHERE 1=1 AND sh.status_plt = 'R' and sh.subplant = ANY($1) and date_part('year', sh.tanggal) = $2
        ) AS a
        GROUP BY a.subplant, a.group_nama, a.item_kode, a.quality, a.bulan
        ORDER BY a.subplant, a.group_nama, a.item_kode, a.quality, a.bulan";
        $res = $db->parameterizedQuery($query, $params);
        while ($r = pg_fetch_assoc($res)) {
            $row["$r[subplant]"]["$r[group_nama]"]["$r[item_kode]"]["$r[quality]"] = '';
            $arr_prod["$r[subplant]"]["$r[group_nama]"]["$r[item_kode]"]["$r[quality]"]["$r[bulan]"] += $r[qty];
            $arr_totprod["$r[subplant]"]["$r[group_nama]"]["$r[item_kode]"]["$r[quality]"] += $r[qty];
            $grantotprod += $r[qty]; 
        }
        
        $query2 = "SELECT a.item_kode, a.subplant, a.quality, a.group_nama, a.bulan, SUM(a.qty)*-1 as qty
        FROM (
            SELECT substring(sh.item_kode from 1 for 2) AS item_kode, i.group_nama, sh.subplant, date_part('month', mp.create_date) as bulan, mp.qty,
            CASE
              WHEN sh.quality = 'EXPORT' THEN '01'
              WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
              WHEN sh.quality = 'LOKAL' THEN '03'
              WHEN sh.quality = 'KW4' THEN '04'
              WHEN sh.quality = 'KW5' THEN '05'
              WHEN sh.quality = 'KW6' THEN '06'
            END AS quality
            FROM tbl_sp_mutasi_pallet mp
            JOIN tbl_sp_hasilbj sh ON (sh.pallet_no = mp.pallet_no)
            JOIN item i on (i.item_kode = sh.item_kode)
            WHERE 1=1 AND ((substring(mp.no_mutasi from 1 for 3) = 'BAM' AND mp.status_mut = 'S') OR (substring(mp.no_mutasi from 1 for 3) ='BAL' AND mp.status_mut = 'R')) and sh.subplant = ANY($1) and date_part('year', mp.create_date) = $2
        ) AS a
        GROUP BY a.subplant, a.group_nama, a.item_kode, a.quality, a.bulan
        ORDER BY a.subplant, a.group_nama, a.item_kode, a.quality, a.bulan";
        $res2 = $db->parameterizedQuery($query2, $params);
        while ($r2 = pg_fetch_assoc($res2)) {
            $row["$r2[subplant]"]["$r2[group_nama]"]["$r2[item_kode]"]["$r2[quality]"] = '';
            $arr_sale["$r2[subplant]"]["$r2[group_nama]"]["$r2[item_kode]"]["$r2[quality]"]["$r2[bulan]"] += $r2[qty];
            $arr_totsale["$r2[subplant]"]["$r2[group_nama]"]["$r2[item_kode]"]["$r2[quality]"] += $r2[qty];
            $grantotsale += $r2[qty];
        }

        $query3 = "SELECT a.item_kode, a.subplant, a.quality, a.group_nama, SUM(a.qty) as qty
        FROM (
            SELECT substring(i.item_kode from 1 for 2) AS item_kode, i.group_nama, ss.production_subplant AS subplant, ss.total_quantity as qty,
            CASE
              WHEN ss.quality = 'EXP' THEN '01'
              WHEN ss.quality = 'ECO' THEN '02'
              WHEN ss.quality = 'LOKAL' THEN '03'
              WHEN ss.quality = 'KW4' THEN '04'
              WHEN ss.quality = 'KW5' THEN '05'
              WHEN ss.quality = 'KW6' THEN '06'
            END AS quality
            FROM summary_stock_by_motif_location ss
            JOIN item i on (i.item_kode = ss.motif_id)
            JOIN category c on(c.category_kode = i.category_kode)
            WHERE 1=1 AND ss.total_quantity > 0 AND ss.production_subplant = ANY($1)
        ) AS a
        GROUP BY a.subplant, a.group_nama, a.item_kode, a.quality
        ORDER BY a.subplant, a.group_nama, a.item_kode, a.quality";
        $res3 = $db->parameterizedQuery($query3, $params3);
        while ($r3 = pg_fetch_assoc($res3)) {
            $arr_stok["$r3[subplant]"]["$r3[group_nama]"]["$r3[item_kode]"]["$r3[quality]"] += $r3[qty];
        }
        ksort($row);
        reset($row);
        foreach ($row as $subp => $a_group_nama) {
            foreach ($a_group_nama as $group_nama => $a_item_kode) {
                foreach ($a_item_kode as $item_kode => $a_quality) {
                    foreach ($a_quality as $quality => $nilai) {
                        $repeatprod = 0;
                        for ($i=1; $i<=12; $i++) { 
                            if($arr_prod[$subp][$group_nama][$item_kode][$quality][$i] > 0) {
                                $repeatprod += 1;
                            }
                        }
                        $response[] = array(
                            'subplant' => $subp,
                            'group_nama' => $group_nama,
                            'ukuran' => $arr_ukuran["$item_kode"],
                            'quality' => $arr_quality["$quality"],
                            'janprod' => $arr_prod[$subp][$group_nama][$item_kode][$quality][1],
                            'febprod' => $arr_prod[$subp][$group_nama][$item_kode][$quality][2],
                            'marprod' => $arr_prod[$subp][$group_nama][$item_kode][$quality][3],
                            'aprprod' => $arr_prod[$subp][$group_nama][$item_kode][$quality][4],
                            'meiprod' => $arr_prod[$subp][$group_nama][$item_kode][$quality][5],
                            'junprod' => $arr_prod[$subp][$group_nama][$item_kode][$quality][6],
                            'julprod' => $arr_prod[$subp][$group_nama][$item_kode][$quality][7],
                            'aguprod' => $arr_prod[$subp][$group_nama][$item_kode][$quality][8],
                            'sepprod' => $arr_prod[$subp][$group_nama][$item_kode][$quality][9],
                            'oktprod' => $arr_prod[$subp][$group_nama][$item_kode][$quality][10],
                            'novprod' => $arr_prod[$subp][$group_nama][$item_kode][$quality][11],
                            'desprod' => $arr_prod[$subp][$group_nama][$item_kode][$quality][12],
                            'totprod' => $arr_totprod[$subp][$group_nama][$item_kode][$quality],
                            'senprod' => ($arr_totprod[$subp][$group_nama][$item_kode][$quality] / $grantotprod * 100),
                            'jansale' => $arr_sale[$subp][$group_nama][$item_kode][$quality][1],
                            'febsale' => $arr_sale[$subp][$group_nama][$item_kode][$quality][2],
                            'marsale' => $arr_sale[$subp][$group_nama][$item_kode][$quality][3],
                            'aprsale' => $arr_sale[$subp][$group_nama][$item_kode][$quality][4],
                            'meisale' => $arr_sale[$subp][$group_nama][$item_kode][$quality][5],
                            'junsale' => $arr_sale[$subp][$group_nama][$item_kode][$quality][6],
                            'julsale' => $arr_sale[$subp][$group_nama][$item_kode][$quality][7],
                            'agusale' => $arr_sale[$subp][$group_nama][$item_kode][$quality][8],
                            'sepsale' => $arr_sale[$subp][$group_nama][$item_kode][$quality][9],
                            'oktsale' => $arr_sale[$subp][$group_nama][$item_kode][$quality][10],
                            'novsale' => $arr_sale[$subp][$group_nama][$item_kode][$quality][11],
                            'dessale' => $arr_sale[$subp][$group_nama][$item_kode][$quality][12],
                            'totsale' => $arr_totsale[$subp][$group_nama][$item_kode][$quality],
                            'sensale' => ($arr_totsale[$subp][$group_nama][$item_kode][$quality] / $grantotsale * 100),
                            'repeatprod' => $repeatprod,
                            'stok' => $arr_stok[$subp][$group_nama][$item_kode][$quality]
                        );
                    }
                }
            }
        }
    } else {
        $query = "SELECT a.item_kode, a.subplant, a.group_nama, a.bulan, SUM(a.qty) as qty
        FROM (
            SELECT substring(sh.item_kode from 1 for 2) AS item_kode, i.group_nama, sh.subplant, date_part('month', sh.tanggal) as bulan, sh.qty
            FROM tbl_sp_hasilbj sh
            JOIN item i on (i.item_kode = sh.item_kode)
            WHERE 1=1 AND sh.status_plt = 'R' and sh.subplant = ANY($1) and date_part('year', sh.tanggal) = $2
        ) AS a
        GROUP BY a.subplant, a.group_nama, a.item_kode, a.bulan
        ORDER BY a.subplant, a.group_nama, a.item_kode, a.bulan";
        $res = $db->parameterizedQuery($query, $params);
        while ($r = pg_fetch_assoc($res)) {
            $row["$r[subplant]"]["$r[group_nama]"]["$r[item_kode]"] = '';
            $arr_prod["$r[subplant]"]["$r[group_nama]"]["$r[item_kode]"]["$r[bulan]"] += $r[qty];
            $arr_totprod["$r[subplant]"]["$r[group_nama]"]["$r[item_kode]"] += $r[qty];
            $grantotprod += $r[qty]; 
        }
        
        $query2 = "SELECT a.item_kode, a.subplant, a.group_nama, a.bulan, SUM(a.qty)*-1 as qty
        FROM (
            SELECT substring(sh.item_kode from 1 for 2) AS item_kode, i.group_nama, sh.subplant, date_part('month', mp.create_date) as bulan, mp.qty
            FROM tbl_sp_mutasi_pallet mp
            JOIN tbl_sp_hasilbj sh ON (sh.pallet_no = mp.pallet_no)
            JOIN item i on (i.item_kode = sh.item_kode)
            WHERE 1=1 AND ((substring(mp.no_mutasi from 1 for 3) = 'BAM' AND mp.status_mut = 'S') OR (substring(mp.no_mutasi from 1 for 3) ='BAL' AND mp.status_mut = 'R')) and sh.subplant = ANY($1) and date_part('year', mp.create_date) = $2
        ) AS a
        GROUP BY a.subplant, a.group_nama, a.item_kode, a.bulan
        ORDER BY a.subplant, a.group_nama, a.item_kode, a.bulan";
        $res2 = $db->parameterizedQuery($query2, $params);
        while ($r2 = pg_fetch_assoc($res2)) {
            $row["$r2[subplant]"]["$r2[group_nama]"]["$r2[item_kode]"] = '';
            $arr_sale["$r2[subplant]"]["$r2[group_nama]"]["$r2[item_kode]"]["$r2[bulan]"] += $r2[qty];
            $arr_totsale["$r2[subplant]"]["$r2[group_nama]"]["$r2[item_kode]"] += $r2[qty];
            $grantotsale += $r2[qty];
        }

        $query3 = "SELECT a.item_kode, a.subplant, a.group_nama, SUM(a.qty) as qty
        FROM (
            SELECT substring(i.item_kode from 1 for 2) AS item_kode, i.group_nama, ss.production_subplant AS subplant, ss.total_quantity as qty
            FROM summary_stock_by_motif_location ss
            JOIN item i on (i.item_kode = ss.motif_id)
            WHERE 1=1 AND ss.total_quantity > 0 AND ss.production_subplant = ANY($1)
        ) AS a
        GROUP BY a.subplant, a.group_nama, a.item_kode
        ORDER BY a.subplant, a.group_nama, a.item_kode";
        $res3 = $db->parameterizedQuery($query3, $params3);
        while ($r3 = pg_fetch_assoc($res3)) {
            $arr_stok["$r3[subplant]"]["$r3[group_nama]"]["$r3[item_kode]"] += $r3[qty];
        }

        ksort($row);
        reset($row);
        foreach ($row as $subp => $a_group_nama) {
            foreach ($a_group_nama as $group_nama => $a_item_kode) {
                foreach ($a_item_kode as $item_kode => $nilai) {
                    $repeatprod = 0;
                    for ($i=1; $i<=12; $i++) { 
                        if($arr_prod[$subp][$group_nama][$item_kode][$i] > 0) {
                            $repeatprod += 1;
                        }
                    }
                    $response[] = array(
                        'subplant' => $subp,
                        'group_nama' => $group_nama,
                        'ukuran' => $arr_ukuran["$item_kode"],
                        'janprod' => $arr_prod[$subp][$group_nama][$item_kode][1],
                        'febprod' => $arr_prod[$subp][$group_nama][$item_kode][2],
                        'marprod' => $arr_prod[$subp][$group_nama][$item_kode][3],
                        'aprprod' => $arr_prod[$subp][$group_nama][$item_kode][4],
                        'meiprod' => $arr_prod[$subp][$group_nama][$item_kode][5],
                        'junprod' => $arr_prod[$subp][$group_nama][$item_kode][6],
                        'julprod' => $arr_prod[$subp][$group_nama][$item_kode][7],
                        'aguprod' => $arr_prod[$subp][$group_nama][$item_kode][8],
                        'sepprod' => $arr_prod[$subp][$group_nama][$item_kode][9],
                        'oktprod' => $arr_prod[$subp][$group_nama][$item_kode][10],
                        'novprod' => $arr_prod[$subp][$group_nama][$item_kode][11],
                        'desprod' => $arr_prod[$subp][$group_nama][$item_kode][12],
                        'totprod' => $arr_totprod[$subp][$group_nama][$item_kode],
                        'senprod' => ($arr_totprod[$subp][$group_nama][$item_kode] / $grantotprod * 100),
                        'jansale' => $arr_sale[$subp][$group_nama][$item_kode][1],
                        'febsale' => $arr_sale[$subp][$group_nama][$item_kode][2],
                        'marsale' => $arr_sale[$subp][$group_nama][$item_kode][3],
                        'aprsale' => $arr_sale[$subp][$group_nama][$item_kode][4],
                        'meisale' => $arr_sale[$subp][$group_nama][$item_kode][5],
                        'junsale' => $arr_sale[$subp][$group_nama][$item_kode][6],
                        'julsale' => $arr_sale[$subp][$group_nama][$item_kode][7],
                        'agusale' => $arr_sale[$subp][$group_nama][$item_kode][8],
                        'sepsale' => $arr_sale[$subp][$group_nama][$item_kode][9],
                        'oktsale' => $arr_sale[$subp][$group_nama][$item_kode][10],
                        'novsale' => $arr_sale[$subp][$group_nama][$item_kode][11],
                        'dessale' => $arr_sale[$subp][$group_nama][$item_kode][12],
                        'totsale' => $arr_totsale[$subp][$group_nama][$item_kode],
                        'sensale' => ($arr_totsale[$subp][$group_nama][$item_kode] / $grantotsale * 100),
                        'repeatprod' => $repeatprod,
                        'stok' => $arr_stok[$subp][$group_nama][$item_kode],
                    );  
                }
            }
        }    
    }
    $db->close();
    HttpUtils::sendJsonResponse($response, $query3);
} catch (PostgresqlDatabaseException $e) {
    HttpUtils::sendError('json', (string) $e,
        Env::isDebug() ? $e->getTrace() : array(),
        $e->isRaisedManually() ? HttpUtils::HTTP_RESPONSE_UNPROCESSABLE_ENTITY : HttpUtils::HTTP_RESPONSE_SERVER_ERROR
    );
}
