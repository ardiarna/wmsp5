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
        $query = "SELECT a.item_kode, a.motif_nama, a.subplant, a.quality, a.group_nama, a.bulan, SUM(a.qty) as qty
        FROM (
            SELECT substring(sh.item_kode from 1 for 2) AS item_kode, c.category_nama||' '||i.color as motif_nama, i.group_nama, sh.subplant, date_part('month', sh.tanggal) as bulan, sh.qty,
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
            JOIN category c on(c.category_kode = i.category_kode)
            WHERE 1=1 AND sh.status_plt = 'R' and sh.subplant = ANY($1) and date_part('year', sh.tanggal) = $2
        ) AS a
        GROUP BY a.item_kode, a.motif_nama, a.subplant, a.quality, a.group_nama, a.bulan
        ORDER BY a.item_kode, a.motif_nama, a.subplant, a.quality, a.bulan";
        $res = $db->parameterizedQuery($query, $params);
        while ($r = pg_fetch_assoc($res)) {
            $row["$r[motif_nama]"]["$r[subplant]"]["$r[quality]"] = $r[group_nama].'@@'.$r[item_kode];
            $arr_prod["$r[motif_nama]"]["$r[subplant]"]["$r[quality]"]["$r[bulan]"] += $r[qty];
            $arr_totprod["$r[motif_nama]"]["$r[subplant]"]["$r[quality]"] += $r[qty];
            $grantotprod += $r[qty]; 
        }
        
        $query2 = "SELECT a.item_kode, a.motif_nama, a.subplant, a.quality, a.group_nama, a.bulan, SUM(a.qty)*-1 as qty
        FROM (
            SELECT substring(sh.item_kode from 1 for 2) AS item_kode, c.category_nama||' '||i.color as motif_nama, i.group_nama, sh.subplant, date_part('month', mp.create_date) as bulan, mp.qty,
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
            JOIN category c on(c.category_kode = i.category_kode)
            WHERE 1=1 AND ((substring(mp.no_mutasi from 1 for 3) = 'BAM' AND mp.status_mut = 'S') OR (substring(mp.no_mutasi from 1 for 3) ='BAL' AND mp.status_mut = 'R')) and sh.subplant = ANY($1) and date_part('year', mp.create_date) = $2
        ) AS a
        GROUP BY a.item_kode, a.motif_nama, a.subplant, a.quality, a.group_nama, a.bulan
        ORDER BY a.item_kode, a.motif_nama, a.subplant, a.quality, a.bulan";
        $res2 = $db->parameterizedQuery($query2, $params);
        while ($r2 = pg_fetch_assoc($res2)) {
            $row["$r2[motif_nama]"]["$r2[subplant]"]["$r2[quality]"] = $r2[group_nama].'@@'.$r2[item_kode];
            $arr_sale["$r2[motif_nama]"]["$r2[subplant]"]["$r2[quality]"]["$r2[bulan]"] += $r2[qty];
            $arr_totsale["$r2[motif_nama]"]["$r2[subplant]"]["$r2[quality]"] += $r2[qty];
            $grantotsale += $r2[qty];
        }

        $query3 = "SELECT a.motif_nama, a.subplant, a.quality, a.group_nama, SUM(a.qty) as qty
        FROM (
            SELECT c.category_nama||' '||i.color as motif_nama, i.group_nama, ss.production_subplant AS subplant, ss.total_quantity as qty,
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
        GROUP BY a.motif_nama, a.subplant, a.quality, a.group_nama
        ORDER BY a.motif_nama, a.subplant, a.quality";
        $res3 = $db->parameterizedQuery($query3, $params3);
        while ($r3 = pg_fetch_assoc($res3)) {
            $arr_stok["$r3[motif_nama]"]["$r3[subplant]"]["$r3[quality]"] += $r3[qty];
        }
        ksort($row);
        reset($row);
        foreach ($row as $motif_nama => $a_subp) {
            foreach ($a_subp as $subp => $a_quality) {
                foreach ($a_quality as $quality => $nilai) {
                    $nil = explode('@@', $nilai);
                    $repeatprod = 0;
                    for ($i=1; $i<=12; $i++) { 
                        if($arr_prod[$motif_nama][$subp][$quality][$i] > 0) {
                            $repeatprod += 1;
                        }
                    }
                    $response[] = array(
                        'subplant' => $subp,
                        'motif_nama' => $motif_nama,
                        'quality' => $arr_quality["$quality"],
                        'group_nama' => $nil[0],
                        'ukuran' => $arr_ukuran["$nil[1]"],
                        'janprod' => $arr_prod[$motif_nama][$subp][$quality][1],
                        'febprod' => $arr_prod[$motif_nama][$subp][$quality][2],
                        'marprod' => $arr_prod[$motif_nama][$subp][$quality][3],
                        'aprprod' => $arr_prod[$motif_nama][$subp][$quality][4],
                        'meiprod' => $arr_prod[$motif_nama][$subp][$quality][5],
                        'junprod' => $arr_prod[$motif_nama][$subp][$quality][6],
                        'julprod' => $arr_prod[$motif_nama][$subp][$quality][7],
                        'aguprod' => $arr_prod[$motif_nama][$subp][$quality][8],
                        'sepprod' => $arr_prod[$motif_nama][$subp][$quality][9],
                        'oktprod' => $arr_prod[$motif_nama][$subp][$quality][10],
                        'novprod' => $arr_prod[$motif_nama][$subp][$quality][11],
                        'desprod' => $arr_prod[$motif_nama][$subp][$quality][12],
                        'totprod' => $arr_totprod[$motif_nama][$subp][$quality],
                        'senprod' => ($arr_totprod[$motif_nama][$subp][$quality] / $grantotprod * 100),
                        'jansale' => $arr_sale[$motif_nama][$subp][$quality][1],
                        'febsale' => $arr_sale[$motif_nama][$subp][$quality][2],
                        'marsale' => $arr_sale[$motif_nama][$subp][$quality][3],
                        'aprsale' => $arr_sale[$motif_nama][$subp][$quality][4],
                        'meisale' => $arr_sale[$motif_nama][$subp][$quality][5],
                        'junsale' => $arr_sale[$motif_nama][$subp][$quality][6],
                        'julsale' => $arr_sale[$motif_nama][$subp][$quality][7],
                        'agusale' => $arr_sale[$motif_nama][$subp][$quality][8],
                        'sepsale' => $arr_sale[$motif_nama][$subp][$quality][9],
                        'oktsale' => $arr_sale[$motif_nama][$subp][$quality][10],
                        'novsale' => $arr_sale[$motif_nama][$subp][$quality][11],
                        'dessale' => $arr_sale[$motif_nama][$subp][$quality][12],
                        'totsale' => $arr_totsale[$motif_nama][$subp][$quality],
                        'sensale' => ($arr_totsale[$motif_nama][$subp][$quality] / $grantotsale * 100),
                        'repeatprod' => $repeatprod,
                        'stok' => $arr_stok[$motif_nama][$subp][$quality]
                    );
                }
            }
        }
    } else {
        $query = "SELECT a.item_kode, a.motif_nama, a.subplant, a.group_nama, a.bulan, SUM(a.qty) as qty
        FROM (
            SELECT substring(sh.item_kode from 1 for 2) AS item_kode, c.category_nama||' '||i.color as motif_nama, i.group_nama, sh.subplant, date_part('month', sh.tanggal) as bulan, sh.qty
            FROM tbl_sp_hasilbj sh
            JOIN item i on (i.item_kode = sh.item_kode)
            JOIN category c on(c.category_kode = i.category_kode)
            WHERE 1=1 AND sh.status_plt = 'R' and sh.subplant = ANY($1) and date_part('year', sh.tanggal) = $2
        ) AS a
        GROUP BY a.item_kode, a.motif_nama, a.subplant, a.group_nama, a.bulan
        ORDER BY a.item_kode, a.motif_nama, a.subplant, a.bulan";
        $res = $db->parameterizedQuery($query, $params);
        while ($r = pg_fetch_assoc($res)) {
            $row["$r[motif_nama]"]["$r[subplant]"] = $r[group_nama].'@@'.$r[item_kode];
            $arr_prod["$r[motif_nama]"]["$r[subplant]"]["$r[bulan]"] += $r[qty];
            $arr_totprod["$r[motif_nama]"]["$r[subplant]"] += $r[qty];
            $grantotprod += $r[qty]; 
        }
        
        $query2 = "SELECT a.item_kode, a.motif_nama, a.subplant, a.group_nama, a.bulan, SUM(a.qty)*-1 as qty
        FROM (
            SELECT substring(sh.item_kode from 1 for 2) AS item_kode, c.category_nama||' '||i.color as motif_nama, i.group_nama, sh.subplant, date_part('month', mp.create_date) as bulan, mp.qty
            FROM tbl_sp_mutasi_pallet mp
            JOIN tbl_sp_hasilbj sh ON (sh.pallet_no = mp.pallet_no)
            JOIN item i on (i.item_kode = sh.item_kode)
            JOIN category c on(c.category_kode = i.category_kode)
            WHERE 1=1 AND ((substring(mp.no_mutasi from 1 for 3) = 'BAM' AND mp.status_mut = 'S') OR (substring(mp.no_mutasi from 1 for 3) ='BAL' AND mp.status_mut = 'R')) and sh.subplant = ANY($1) and date_part('year', mp.create_date) = $2
        ) AS a
        GROUP BY a.item_kode, a.motif_nama, a.subplant, a.group_nama, a.bulan
        ORDER BY a.item_kode, a.motif_nama, a.subplant, a.bulan";
        $res2 = $db->parameterizedQuery($query2, $params);
        while ($r2 = pg_fetch_assoc($res2)) {
            $row["$r2[motif_nama]"]["$r2[subplant]"] = $r2[group_nama].'@@'.$r2[item_kode];
            $arr_sale["$r2[motif_nama]"]["$r2[subplant]"]["$r2[bulan]"] += $r2[qty];
            $arr_totsale["$r2[motif_nama]"]["$r2[subplant]"] += $r2[qty];
            $grantotsale += $r2[qty];
        }

        $query3 = "SELECT a.motif_nama, a.subplant, a.group_nama, SUM(a.qty) as qty
        FROM (
            SELECT c.category_nama||' '||i.color as motif_nama, i.group_nama, ss.production_subplant AS subplant, ss.total_quantity as qty
            FROM summary_stock_by_motif_location ss
            JOIN item i on (i.item_kode = ss.motif_id)
            JOIN category c on(c.category_kode = i.category_kode)
            WHERE 1=1 AND ss.total_quantity > 0 AND ss.production_subplant = ANY($1)
        ) AS a
        GROUP BY a.motif_nama, a.subplant, a.group_nama
        ORDER BY a.motif_nama, a.subplant";
        $res3 = $db->parameterizedQuery($query3, $params3);
        while ($r3 = pg_fetch_assoc($res3)) {
            $arr_stok["$r3[motif_nama]"]["$r3[subplant]"] += $r3[qty];
        }

        ksort($row);
        reset($row);
        foreach ($row as $motif_nama => $a_subp) {
            foreach ($a_subp as $subp => $nilai) {
                $nil = explode('@@', $nilai);
                $repeatprod = 0;
                for ($i=1; $i<=12; $i++) { 
                    if($arr_prod[$motif_nama][$subp][$i] > 0) {
                        $repeatprod += 1;
                    }
                }
                $response[] = array(
                    'subplant' => $subp,
                    'motif_nama' => $motif_nama,
                    'group_nama' => $nil[0],
                    'ukuran' => $arr_ukuran["$nil[1]"],
                    'janprod' => $arr_prod[$motif_nama][$subp][1],
                    'febprod' => $arr_prod[$motif_nama][$subp][2],
                    'marprod' => $arr_prod[$motif_nama][$subp][3],
                    'aprprod' => $arr_prod[$motif_nama][$subp][4],
                    'meiprod' => $arr_prod[$motif_nama][$subp][5],
                    'junprod' => $arr_prod[$motif_nama][$subp][6],
                    'julprod' => $arr_prod[$motif_nama][$subp][7],
                    'aguprod' => $arr_prod[$motif_nama][$subp][8],
                    'sepprod' => $arr_prod[$motif_nama][$subp][9],
                    'oktprod' => $arr_prod[$motif_nama][$subp][10],
                    'novprod' => $arr_prod[$motif_nama][$subp][11],
                    'desprod' => $arr_prod[$motif_nama][$subp][12],
                    'totprod' => $arr_totprod[$motif_nama][$subp],
                    'senprod' => ($arr_totprod[$motif_nama][$subp] / $grantotprod * 100),
                    'jansale' => $arr_sale[$motif_nama][$subp][1],
                    'febsale' => $arr_sale[$motif_nama][$subp][2],
                    'marsale' => $arr_sale[$motif_nama][$subp][3],
                    'aprsale' => $arr_sale[$motif_nama][$subp][4],
                    'meisale' => $arr_sale[$motif_nama][$subp][5],
                    'junsale' => $arr_sale[$motif_nama][$subp][6],
                    'julsale' => $arr_sale[$motif_nama][$subp][7],
                    'agusale' => $arr_sale[$motif_nama][$subp][8],
                    'sepsale' => $arr_sale[$motif_nama][$subp][9],
                    'oktsale' => $arr_sale[$motif_nama][$subp][10],
                    'novsale' => $arr_sale[$motif_nama][$subp][11],
                    'dessale' => $arr_sale[$motif_nama][$subp][12],
                    'totsale' => $arr_totsale[$motif_nama][$subp],
                    'sensale' => ($arr_totsale[$motif_nama][$subp] / $grantotsale * 100),
                    'repeatprod' => $repeatprod,
                    'stok' => $arr_stok[$motif_nama][$subp],
                );
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
