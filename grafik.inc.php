<?php

$app_id     = "4534tghghgplan5";
$app_dbtype = "postgres";
$app_host   = "db.p5.arwanacitra.com";
$app_port   = "5432";
$app_dbname = "armasi_local";
$app_user   = "armasi";
$app_pass   = "flluS8m4cYiluw9oElLk";

$app_plan_id  = "5";

$conn = pg_connect( "host=$app_host port=$app_port dbname=$app_dbname user=$app_user password=$app_pass");
if(!$conn){
  print("Connection FAILED");
  exit;
}

$arr_ukuran = array('05' => '20 X 20', '06' => '30 X 30', '07' => '40 X 40', '08' => '20 X 25', '09' => '25 X 40', '10' => '25 X 25', '11' => '50 X 50', '12' => '25 X 50', '13' => '60 X 60');

$arr_quality = array('01' => 'EXP', '02' => 'ECO', '03' => 'LOCAL', '04' => 'KW4', '05' => 'BBM SQ', '06' => 'BBM OS');

$arr_jam = array('0' => '00-01', '1' => '01-02', '2' => '02-03', '3' => '03-04', '4' => '04-05', '5' => '05-06', '6' => '06-07', '7' => '07-08', '8' => '08-09', '9' => '09-10', '10' => '10-11', '11' => '11-12', '12' => '12-13', '13' => '13-14', '14' => '14-15', '15' => '15-16', '16' => '16-17', '17' => '17-18', '18' => '18-19', '19' => '19-20', '20' => '20-21', '21' => '21-22', '22' => '22-23', '23' => '23-00');

$arr_bln = array('1' => 'Jan', '2' => 'Feb', '3' => 'Mar', '4' => 'Apr', '5' => 'Mei', '6' => 'Jun', '7' => 'Jul', '8' => 'Agu', '9' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Des');

$arr_shift = array('1' => 'Shift 1', '2' => 'Shift 2', '3' => 'Shift 3');

$arr_kapasitas_gudang = array('1' => 549840, '2' => 1443806, '3' => 1115356, '4' => 1560656, '5' => 1221648);

$mode = $_GET['mode'];
switch ($mode) {
  case "load";
    load();
  break;
  case "produksi";
    produksi();
  break;
  case "salesvsprod";
    salesvsprod();
  break;
  case "barangpecah";
    barangpecah();
  break;
  case "foc";
    foc();
  break;
  case "aging";
    aging();
  break;
}

function load() {
  global $conn, $arr_ukuran, $arr_quality, $arr_jam, $arr_bln, $arr_shift;

  $jns = $_GET['jns'];
  $tpe = $_GET['tpe'];
  $tgl = $_GET['tgl'];
  $tglb = $_GET['tglb'];
  $thn = $_GET['thn'];

  if($_GET['muat'] == 'all') {
    $where = "AND ((substring(mp.no_mutasi from 1 for 3) = 'BAM' AND (mp.status_mut = 'S' OR mp.status_mut = 'R')) OR (substring(mp.no_mutasi from 1 for 3) = 'BAL' AND (mp.status_mut = 'S' OR mp.status_mut = 'R')))";
  } else if(strtoupper($_GET['muat']) == 'BAM') {
    $where = "AND substring(mp.no_mutasi from 1 for 3) = 'BAM' AND (mp.status_mut = 'S' OR mp.status_mut = 'R')";
  } else if(strtoupper($_GET['muat']) == 'BAL') {
    $where = "AND substring(mp.no_mutasi from 1 for 3) = 'BAL' AND (mp.status_mut = 'S' OR mp.status_mut = 'R')";  
  }
  
  if($jns == 'H') {
    $sql = "SELECT jam AS kat, item_kode, quality, sum(qty)*-1 AS jml_m
      FROM (
        SELECT no_mutasi, item_kode, quality, date_part('hour', max(create_date)) AS jam, SUM(qty) AS qty
        FROM (
          SELECT mp.no_mutasi, mp.pallet_no, mp.qty, mp.create_date,
            substring(sh.item_kode from 1 for 2) AS item_kode,
            CASE
              WHEN sh.quality = 'EXPORT' THEN '01'
              WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
              WHEN sh.quality = 'LOKAL' THEN '03'
              WHEN sh.quality = 'KW4' THEN '04'
              WHEN sh.quality = 'KW5' THEN '05'
              WHEN sh.quality = 'KW6' THEN '06'
            END AS quality
          FROM tbl_sp_mutasi_pallet mp
          JOIN tbl_sp_hasilbj sh ON (mp.pallet_no = sh.pallet_no)
          WHERE 1=1 $where
          AND TO_CHAR(mp.create_date, 'YYYY-MM-DD') = '{$tgl}'
        ) AS a
        GROUP BY no_mutasi, item_kode, quality
      ) AS z
      GROUP BY jam, item_kode, quality
      ORDER BY jam, item_kode, quality";
    $arr_kategori = $arr_jam;
  } else if($jns == 'B') {
    $sql = "SELECT bln AS kat, jam, item_kode, quality, sum(qty)*-1 AS jml_m
      FROM (
        SELECT no_mutasi, item_kode, quality, date_part('month', max(create_date)) AS bln, date_part('hour', max(create_date)) AS jam, SUM(qty) AS qty
        FROM (
          SELECT mp.no_mutasi, mp.pallet_no, mp.qty, mp.create_date,
            substring(sh.item_kode from 1 for 2) AS item_kode,
            CASE
              WHEN sh.quality = 'EXPORT' THEN '01'
              WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
              WHEN sh.quality = 'LOKAL' THEN '03'
              WHEN sh.quality = 'KW4' THEN '04'
              WHEN sh.quality = 'KW5' THEN '05'
              WHEN sh.quality = 'KW6' THEN '06'
            END AS quality
          FROM tbl_sp_mutasi_pallet mp
          JOIN tbl_sp_hasilbj sh ON (mp.pallet_no = sh.pallet_no)
          WHERE 1=1 $where
          AND date_part('year', mp.create_date) = '{$thn}'
        ) AS a
        GROUP BY no_mutasi, item_kode, quality
      ) AS z
      GROUP BY bln, jam, item_kode, quality
      ORDER BY bln, jam, item_kode, quality";
    $arr_kategori = $arr_bln;
  } else if($jns == 'M') {
    $lastWeek = date("Y-m-d", strtotime("-6 days"));
    $today = date("Y-m-d"); 
    $sql = "SELECT tanggal AS kat, jam, item_kode, quality, sum(qty)*-1 AS jml_m
      FROM (
        SELECT no_mutasi, item_kode, quality, TO_CHAR(max(create_date), 'YYYY-MM-DD') as tanggal, date_part('hour', max(create_date)) AS jam, SUM(qty) AS qty
        FROM (
          SELECT mp.no_mutasi, mp.pallet_no, mp.qty, mp.create_date,
            substring(sh.item_kode from 1 for 2) AS item_kode,
            CASE
              WHEN sh.quality = 'EXPORT' THEN '01'
              WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
              WHEN sh.quality = 'LOKAL' THEN '03'
              WHEN sh.quality = 'KW4' THEN '04'
              WHEN sh.quality = 'KW5' THEN '05'
              WHEN sh.quality = 'KW6' THEN '06'
            END AS quality
          FROM tbl_sp_mutasi_pallet mp
          JOIN tbl_sp_hasilbj sh ON (mp.pallet_no = sh.pallet_no)
          WHERE 1=1 $where
          AND TO_CHAR(mp.create_date, 'YYYY-MM-DD') >= '{$lastWeek}' 
          AND TO_CHAR(mp.create_date, 'YYYY-MM-DD') <= '{$today}'
        ) AS a
        GROUP BY no_mutasi, item_kode, quality
      ) AS z
      GROUP BY tanggal, jam, item_kode, quality
      ORDER BY tanggal, jam, item_kode, quality";
  } else if($jns == 'S') {
    $sql = "SELECT jam AS kat, item_kode, quality, sum(qty)*-1 AS jml_m
      FROM (
        SELECT no_mutasi, item_kode, quality, 
          CASE 
            WHEN date_part('hour', max(create_date)) >= 8 AND date_part('hour', max(create_date)) <= 15 THEN '1'
            WHEN date_part('hour', max(create_date)) >= 16 AND date_part('hour', max(create_date)) <= 23 THEN '2'
            ELSE '3'
          END AS jam, 
          SUM(qty) AS qty
        FROM (
          SELECT mp.no_mutasi, mp.pallet_no, mp.qty, mp.create_date,
            substring(sh.item_kode from 1 for 2) AS item_kode,
            CASE
              WHEN sh.quality = 'EXPORT' THEN '01'
              WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
              WHEN sh.quality = 'LOKAL' THEN '03'
              WHEN sh.quality = 'KW4' THEN '04'
              WHEN sh.quality = 'KW5' THEN '05'
              WHEN sh.quality = 'KW6' THEN '06'
            END AS quality
          FROM tbl_sp_mutasi_pallet mp
          JOIN tbl_sp_hasilbj sh ON (mp.pallet_no = sh.pallet_no)
          WHERE 1=1 $where
          AND TO_CHAR(mp.create_date, 'YYYY-MM-DD') = '{$tgl}'
        ) AS a
        GROUP BY no_mutasi, item_kode, quality
      ) AS z
      GROUP BY jam, item_kode, quality
      ORDER BY jam, item_kode, quality";
    $arr_kategori = $arr_shift;
  } else if($jns == 'P') {
    $sql = "SELECT tanggal AS kat, jam, item_kode, quality, sum(qty)*-1 AS jml_m
      FROM (
        SELECT no_mutasi, item_kode, quality, TO_CHAR(max(create_date), 'YYYY-MM-DD') as tanggal, date_part('hour', max(create_date)) AS jam, SUM(qty) AS qty
        FROM (
          SELECT mp.no_mutasi, mp.pallet_no, mp.qty, mp.create_date,
            substring(sh.item_kode from 1 for 2) AS item_kode,
            CASE
              WHEN sh.quality = 'EXPORT' THEN '01'
              WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
              WHEN sh.quality = 'LOKAL' THEN '03'
              WHEN sh.quality = 'KW4' THEN '04'
              WHEN sh.quality = 'KW5' THEN '05'
              WHEN sh.quality = 'KW6' THEN '06'
            END AS quality
          FROM tbl_sp_mutasi_pallet mp
          JOIN tbl_sp_hasilbj sh ON (mp.pallet_no = sh.pallet_no)
          WHERE 1=1 $where
          AND TO_CHAR(mp.create_date, 'YYYY-MM-DD') >= '{$tgl}' 
          AND TO_CHAR(mp.create_date, 'YYYY-MM-DD') <= '{$tglb}'
        ) AS a
        GROUP BY no_mutasi, item_kode, quality
      ) AS z
      GROUP BY tanggal, jam, item_kode, quality
      ORDER BY tanggal, jam, item_kode, quality";
  } 

  if($jns == 'H' || $jns == 'S' || ($jns == 'B' && $tpe <> 'shift') || ($jns == 'M' && $tpe <> 'shift') || ($jns == 'P' && $tpe <> 'shift')) {
    $query = pg_query($conn, $sql);
    while($r = pg_fetch_array($query)) {
      if($r[quality]) {
        $ada_ukuran["$r[item_kode]"]["$r[quality]"] = '';
        $arr_nilai["$r[kat]"] += intval($r[jml_m]);
        $arr_tbl_nilai["$r[kat]"]["$r[item_kode]"]["$r[quality]"] += intval($r[jml_m]);
      }
    }

    if($jns == 'M' || $jns == 'P') {
      ksort($arr_nilai);
      reset($arr_nilai);
      foreach ($arr_nilai as $tanggal => $value) {
        $arr_kategori[$tanggal] = $tanggal;
      }
    }

    foreach ($arr_kategori as $kat => $value) {
      $data[] = array($value, $arr_nilai[$kat]); 
    }
    ksort($ada_ukuran);
    reset($ada_ukuran);
    foreach ($ada_ukuran as $ukuran => $a_quality) {
      foreach ($a_quality as $quality => $value) {
        if($quality) {
          foreach ($arr_kategori as $kat => $value2) {
            if($arr_tbl_nilai[$kat][$ukuran][$quality]) {
              $arr_baris[$ukuran][$quality] = $arr_ukuran[$ukuran].' '.$arr_quality[$quality];
            } 
          }
        }
      }  
    }
    $responce->data_a[0]['name'] = $jns;
    $responce->data_a[0]['colorByPoint'] = true;
    $responce->data_a[0]['data'] = $data;
    $tbl = '<table class="adaborder"><tr><th>UKURAN</th>';
    foreach ($arr_kategori as $kat => $kat_lbl) {
       $tbl .= '<th>'.$kat_lbl.'</th>'; 
    }
    $tbl .= '<th>TOTAL</th></tr>';
    ksort($arr_baris);
    reset($arr_baris);
    foreach ($arr_baris as $ukuran => $a_quality) {
      ksort($a_quality);
      reset($a_quality);
      foreach ($a_quality as $quality => $lbl) {
        $tbl .= '<tr><th>'.$lbl.'</th>';
        $tot_brs = 0;
        foreach ($arr_kategori as $kat => $kat_lbl) {
          $tot_brs += $arr_tbl_nilai[$kat][$ukuran][$quality];
          $tbl_nilai = $arr_tbl_nilai[$kat][$ukuran][$quality] ? number_format($arr_tbl_nilai[$kat][$ukuran][$quality]) : '';
          $tbl .= '<td style="text-align:right;">'.$tbl_nilai.'</td>';  
        }
        $tbl .= '<td style="text-align:right;">'.number_format($tot_brs).'</td></tr>';
      } 
    }
    $tbl .= '<tr><th>TOTAL</th>';
    $tot_brs = 0;
    foreach ($arr_kategori as $kat => $kat_lbl) {
      $tot_brs += $arr_nilai[$kat];
      $total = $arr_nilai[$kat] ? number_format($arr_nilai[$kat]) : '';
      $tbl .= '<th style="text-align:right;">'.$total.'</th>';  
    }
    $tbl .= '<th style="text-align:right;">'.number_format($tot_brs).'</th></tr></table>';
  } else {
    $query = pg_query($conn, $sql);
    while($r = pg_fetch_array($query)) {
      if($r[quality]) {
        $ada_ukuran["$r[item_kode]"]["$r[quality]"] = '';
        $arr_nilai["$r[kat]"] += intval($r[jml_m]);
        if(intval($r[jam]) >= 8 && intval($r[jam]) <= 15) {
          $nilai_1["$r[kat]"] += intval($r[jml_m]);
          $arr_tbl_nilai_1["$r[kat]"]["$r[item_kode]"]["$r[quality]"] += intval($r[jml_m]);
        } else if(intval($r[jam]) >= 16 && intval($r[jam]) <= 23) {
          $nilai_2["$r[kat]"] += intval($r[jml_m]);
          $arr_tbl_nilai_2["$r[kat]"]["$r[item_kode]"]["$r[quality]"] += intval($r[jml_m]);
        } else {
          $nilai_3["$r[kat]"] += intval($r[jml_m]);
          $arr_tbl_nilai_3["$r[kat]"]["$r[item_kode]"]["$r[quality]"] += intval($r[jml_m]);
        }
      }
    }

    if($jns == 'M' || $jns == 'P') {
      ksort($arr_nilai);
      reset($arr_nilai);
      foreach ($arr_nilai as $tanggal => $value) {
        $arr_kategori[$tanggal] = $tanggal;
      }
    }

    $i = 0;
    foreach ($arr_kategori as $kat => $value) {
      $kategori[] = $value;
      $shift_1[$i] = $nilai_1[$kat] ? ($nilai_1[$kat]) : 0;
      $shift_2[$i] = $nilai_2[$kat] ? ($nilai_2[$kat]) : 0;
      $shift_3[$i] = $nilai_3[$kat] ? ($nilai_3[$kat]) : 0;
      $i++;
    }
    $responce->kategori = $kategori;
    $responce->data_a[0]['name'] = 'SHIFT 1';
    $responce->data_a[0]['data'] = $shift_1;
    $responce->data_a[0]['color'] = 'green';
    $responce->data_a[1]['name'] = 'SHIFT 2';
    $responce->data_a[1]['data'] = $shift_2;
    $responce->data_a[1]['color'] = 'red';
    $responce->data_a[2]['name'] = 'SHIFT 3';
    $responce->data_a[2]['data'] = $shift_3;

    $tbl = '<table class="adaborder"><tr><th rowspan="2">UKURAN</th>';
    foreach ($arr_kategori as $kat => $kat_lbl) {
      $tbl .= '<th colspan="3">'.$kat_lbl.'</th>'; 
    }
    $tbl .= '<th rowspan="2">TOTAL</th></tr><tr>';
    foreach ($arr_kategori as $kat => $kat_lbl) {
      $tbl .= '<th>SHIFT 1</th><th>SHIFT 2</th><th>SHIFT 3</th>'; 
    }
    $tbl .= '</tr>';

    ksort($ada_ukuran);
    reset($ada_ukuran);
    foreach ($ada_ukuran as $ukuran => $a_quality) {
      foreach ($a_quality as $quality => $value) {
        if($quality) {
          foreach ($arr_kategori as $kat => $value2) {
            if($arr_tbl_nilai_1[$kat][$ukuran][$quality] || $arr_tbl_nilai_2[$kat][$ukuran][$quality] || $arr_tbl_nilai_3[$kat][$ukuran][$quality]) {
              $arr_baris[$ukuran][$quality] = $arr_ukuran[$ukuran].' '.$arr_quality[$quality];
            } 
          }
        }
      }  
    }

    ksort($arr_baris);
    reset($arr_baris);
    foreach ($arr_baris as $ukuran => $a_quality) {
      ksort($a_quality);
      reset($a_quality);
      foreach ($a_quality as $quality => $lbl) {
        $tbl .= '<tr><th>'.$lbl.'</th>';
        $tot_brs = 0;
        foreach ($arr_kategori as $kat => $kat_lbl) {
          $tot_brs += $arr_tbl_nilai_1[$kat][$ukuran][$quality] + $arr_tbl_nilai_2[$kat][$ukuran][$quality] + $arr_tbl_nilai_3[$kat][$ukuran][$quality];
          $tbl_nilai_1 = $arr_tbl_nilai_1[$kat][$ukuran][$quality] ? number_format($arr_tbl_nilai_1[$kat][$ukuran][$quality]) : '';
          $tbl_nilai_2 = $arr_tbl_nilai_2[$kat][$ukuran][$quality] ? number_format($arr_tbl_nilai_2[$kat][$ukuran][$quality]) : '';
          $tbl_nilai_3 = $arr_tbl_nilai_3[$kat][$ukuran][$quality] ? number_format($arr_tbl_nilai_3[$kat][$ukuran][$quality]) : '';
          $tbl .= '<td style="text-align:right;">'.$tbl_nilai_1.'</td>';
          $tbl .= '<td style="text-align:right;">'.$tbl_nilai_2.'</td>';
          $tbl .= '<td style="text-align:right;">'.$tbl_nilai_3.'</td>';  
        }
        $tbl .= '<td style="text-align:right;">'.number_format($tot_brs).'</td></tr>';
      } 
    }
    $tbl .= '<tr><th>TOTAL</th>';
    $tot_brs = 0;
    foreach ($arr_kategori as $kat => $kat_lbl) {
      $tot_brs += $nilai_1[$kat] + $nilai_2[$kat] + $nilai_3[$kat];
      $total_1 = $nilai_1[$kat] ? number_format($nilai_1[$kat]) : '';
      $total_2 = $nilai_2[$kat] ? number_format($nilai_2[$kat]) : '';
      $total_3 = $nilai_3[$kat] ? number_format($nilai_3[$kat]) : '';
      $tbl .= '<th style="text-align:right;">'.$total_1.'</th>';
      $tbl .= '<th style="text-align:right;">'.$total_2.'</th>';
      $tbl .= '<th style="text-align:right;">'.$total_3.'</th>';  
    }
    $tbl .= '<th style="text-align:right;">'.number_format($tot_brs).'</th></tr></table>';
    $responce->tbl = $tbl;
  }
  $responce->tbl = $tbl;
  echo json_encode($responce);
}

function produksi() {
  global $conn, $arr_ukuran, $arr_quality, $arr_jam, $arr_bln, $arr_shift;

  $jns = $_GET['jns'];
  $tpe = $_GET['tpe'];
  $tgl = $_GET['tgl'];
  $tglb = $_GET['tglb'];
  $thn = $_GET['thn'];
  $subplan = $_GET['sub'];
  $line = $_GET['line'];

  $whsubplan = ($subplan == '' || strtolower($subplan) == 'all') ? "" : " AND sh.subplant = '{$subplan}' ";
  $whline = ($line == '' || strtolower($line) == 'all') ? "" : " AND sh.line = '{$line}' ";

  $arr_nilai = array();
  $arr_baris = array();
  if($jns == 'H') {
    $sql = "SELECT item_kode, quality, jam, sum(qty) AS jml_m
      FROM (
        SELECT date_part('hour', sh.update_tran) AS jam, substring(sh.item_kode from 1 for 2) AS item_kode, sh.qty,
        CASE
          WHEN sh.quality = 'EXPORT' THEN '01'
          WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
          WHEN sh.quality = 'LOKAL' THEN '03'
          WHEN sh.quality = 'KW4' THEN '04'
          WHEN sh.quality = 'KW5' THEN '05'
          WHEN sh.quality = 'KW6' THEN '06'
        END AS quality 
        FROM tbl_sp_hasilbj sh
        WHERE sh.tanggal = '{$tgl}' AND status_plt = 'R' $whsubplan $whline
      ) AS z
      GROUP BY item_kode, quality, jam
      ORDER BY item_kode, quality, jam";
    $query = pg_query($conn, $sql);
    $arr_nilai = array();
    while($r = pg_fetch_array($query)) {
      $ada_ukuran[$r[item_kode]][$r[quality]] = '';
      $arr_nilai[$r[item_kode]][$r[quality]][$r[jam]] = intval($r[jml_m]);
    }
    $arr_kategori = $arr_jam;
  } else if($jns == 'B') { 
    $sql = "SELECT item_kode, quality, bln, sum(qty) AS jml_m
      FROM (
        SELECT date_part('month', sh.tanggal) AS bln, substring(sh.item_kode from 1 for 2) AS item_kode, sh.qty,
        CASE
          WHEN sh.quality = 'EXPORT' THEN '01'
          WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
          WHEN sh.quality = 'LOKAL' THEN '03'
          WHEN sh.quality = 'KW4' THEN '04'
          WHEN sh.quality = 'KW5' THEN '05'
          WHEN sh.quality = 'KW6' THEN '06'
        END AS quality 
        FROM tbl_sp_hasilbj sh
        WHERE date_part('year', sh.tanggal) = '{$thn}' AND status_plt = 'R' $whsubplan $whline
      ) AS z
      GROUP BY item_kode, quality, bln 
      ORDER BY item_kode, quality, bln";
    $query = pg_query($conn, $sql);
    $arr_nilai = array();
    while($r = pg_fetch_array($query)) {
      $ada_ukuran[$r[item_kode]][$r[quality]] = '';
      $arr_nilai[$r[item_kode]][$r[quality]][$r[bln]] = intval($r[jml_m]);
    }
    $arr_kategori = $arr_bln;
  } else if($jns == 'M') {
    $lastWeek = date("Y-m-d", strtotime("-6 days"));
    $today = date("Y-m-d"); 
    $sql = "SELECT item_kode, quality, tanggal, sum(qty) AS jml_m
      FROM (
        SELECT sh.tanggal, substring(sh.item_kode from 1 for 2) AS item_kode, sh.qty,
        CASE
          WHEN sh.quality = 'EXPORT' THEN '01'
          WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
          WHEN sh.quality = 'LOKAL' THEN '03'
          WHEN sh.quality = 'KW4' THEN '04'
          WHEN sh.quality = 'KW5' THEN '05'
          WHEN sh.quality = 'KW6' THEN '06'
        END AS quality 
        FROM tbl_sp_hasilbj sh
        WHERE sh.tanggal >= '{$lastWeek}' AND sh.tanggal <= '{$today}' AND status_plt = 'R' $whsubplan $whline
      ) AS z
      GROUP BY item_kode, quality, tanggal 
      ORDER BY item_kode, quality, tanggal";
    $query = pg_query($conn, $sql);
    while($r = pg_fetch_array($query)) {
      $arr_tanggal[$r[tanggal]] = $r[tanggal];
      $ada_ukuran[$r[item_kode]][$r[quality]] = '';
      $arr_nilai[$r[item_kode]][$r[quality]][$r[tanggal]] = intval($r[jml_m]);
    }
    ksort($arr_tanggal);
    reset($arr_tanggal);
    $arr_kategori = $arr_tanggal;
  } else if($jns == 'S') { 
    $sql = "SELECT item_kode, quality, shift, sum(qty) AS jml_m
      FROM (
        SELECT sh.shift, substring(sh.item_kode from 1 for 2) AS item_kode, sh.qty,
        CASE
          WHEN sh.quality = 'EXPORT' THEN '01'
          WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
          WHEN sh.quality = 'LOKAL' THEN '03'
          WHEN sh.quality = 'KW4' THEN '04'
          WHEN sh.quality = 'KW5' THEN '05'
          WHEN sh.quality = 'KW6' THEN '06'
        END AS quality
        FROM tbl_sp_hasilbj sh
        WHERE sh.tanggal = '{$tgl}' AND status_plt = 'R' $whsubplan $whline
      ) AS z
      GROUP BY item_kode, quality, shift 
      ORDER BY item_kode, quality, shift";
    $query = pg_query($conn, $sql);
    while($r = pg_fetch_array($query)) {
      $ada_ukuran[$r[item_kode]][$r[quality]] = '';
      $arr_nilai[$r[item_kode]][$r[quality]][$r[shift]] = intval($r[jml_m]);
    }
    $arr_kategori = $arr_shift;
  } else if($jns == 'P') {
    $sql = "SELECT item_kode, quality, TO_CHAR(tanggal, 'YYYY-MM-DD') as tanggal, sum(qty) AS jml_m
      FROM (
        SELECT sh.tanggal, substring(sh.item_kode from 1 for 2) AS item_kode, sh.qty,
        CASE
          WHEN sh.quality = 'EXPORT' THEN '01'
          WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
          WHEN sh.quality = 'LOKAL' THEN '03'
          WHEN sh.quality = 'KW4' THEN '04'
          WHEN sh.quality = 'KW5' THEN '05'
          WHEN sh.quality = 'KW6' THEN '06'
        END AS quality
        FROM tbl_sp_hasilbj sh
        WHERE sh.tanggal >= '{$tgl}' AND sh.tanggal <= '{$tglb}' AND status_plt = 'R' $whsubplan $whline 
      ) AS z
      GROUP BY item_kode, quality, TO_CHAR(tanggal, 'YYYY-MM-DD') 
      ORDER BY item_kode, quality, tanggal";
    $query = pg_query($conn, $sql);
    while($r = pg_fetch_array($query)) {
      $arr_tanggal[$r[tanggal]] = $r[tanggal];
      $ada_ukuran[$r[item_kode]][$r[quality]] = '';
      $arr_nilai[$r[item_kode]][$r[quality]][$r[tanggal]] = intval($r[jml_m]);
    }
    ksort($arr_tanggal);
    reset($arr_tanggal);
    $arr_kategori = $arr_tanggal;
  }
  foreach ($arr_kategori as $kat => $kat_lbl) {
    $kategori[] = $kat_lbl;  
  }
  foreach ($ada_ukuran as $ukuran => $a_quality) {
    foreach ($a_quality as $quality => $value) {
      if($quality) {
        $i = 0;
        foreach ($arr_kategori as $kat => $value2) {
          if($arr_nilai[$ukuran][$quality][$kat]) {
            $arr_baris[$ukuran][$quality] = $arr_ukuran[$ukuran].' '.$arr_quality[$quality];
            $nilai[$ukuran][$quality][$i] = $arr_nilai[$ukuran][$quality][$kat];
          } else {
            $nilai[$ukuran][$quality][$i] = 0;
          }
          $i++;
        }
      }
    }  
  }
  $i = 0;
  ksort($arr_baris);
  reset($arr_baris);
  foreach ($arr_baris as $ukuran => $a_quality) {
    ksort($a_quality);
    reset($a_quality);
    foreach ($a_quality as $quality => $lbl) {
      $responce->data_a[$i]['name'] = $lbl;
      $responce->data_a[$i]['data'] = $nilai[$ukuran][$quality];
      $i++;
    } 
  }
  $responce->kategori = $kategori;
  echo json_encode($responce);
}

function salesvsprod() {
  global $conn, $app_plan_id, $arr_ukuran, $arr_quality, $arr_kapasitas_gudang;

  $jns = $_GET['jns'];
  $tgl = $_GET['tgl'];
  $tglb = $_GET['tglb'];
  $bln = $_GET['bln'];
  $thn = $_GET['thn'];
  $subplan = $_GET['sub'];

  if($jns == 'H') {
    $where1 = " AND sh.tanggal = '{$tgl}'";
    $where2 = " AND TO_CHAR(mp.create_date, 'YYYY-MM-DD') = '{$tgl}'";
    $where3 = $tgl;
  } else if($jns == 'M') {
    $where1 = " AND sh.tanggal >= '{$tgl}' AND sh.tanggal <= '{$tglb}'";
    $where2 = " AND TO_CHAR(mp.create_date, 'YYYY-MM-DD') >= '{$tgl}' AND TO_CHAR(mp.create_date, 'YYYY-MM-DD') <= '{$tglb}'";
    $where3 = $tgl;
  } else if($jns == 'B') {
    $where1 = " AND date_part('year', sh.tanggal) = '{$thn}' AND date_part('month', sh.tanggal) = '{$bln}'";
    $where2 = " AND date_part('year', mp.create_date) = '{$thn}' AND date_part('month', mp.create_date) = '{$bln}'";
    $where3 = $thn."/".$bln."/1";
  }

  $whsubplan = ($subplan == '' || strtolower($subplan) == 'all') ? "" : " AND sh.subplant = '{$subplan}' ";

  $sql = "SELECT item_kode, quality, sum(qty) AS jml_prod
    FROM (
      SELECT substring(sh.item_kode from 1 for 2) AS item_kode, sh.qty ,
      CASE
        WHEN sh.quality = 'EXPORT' THEN '01'
        WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
        WHEN sh.quality = 'LOKAL' THEN '03'
        WHEN sh.quality = 'KW4' THEN '04'
        WHEN sh.quality = 'KW5' THEN '05'
        WHEN sh.quality = 'KW6' THEN '06'
      END AS quality
      FROM tbl_sp_hasilbj sh
      WHERE 1=1 $where1 $whsubplan AND status_plt = 'R'
    ) AS z
    GROUP BY item_kode, quality";
  $query = pg_query($conn, $sql);

  $arr_nilai_prod = array();
  while($r = pg_fetch_array($query)) {
    $ada_ukuran[$r[item_kode]][$r[quality]] = '';
    $arr_nilai_prod[$r[item_kode]][$r[quality]] = intval($r[jml_prod]);
  }

  $sql2 = "SELECT item_kode, quality, sum(qty)*-1 AS jml_sale
    FROM (
      SELECT substring(sh.item_kode from 1 for 2) AS item_kode, mp.qty,
      CASE
        WHEN sh.quality = 'EXPORT' THEN '01'
        WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
        WHEN sh.quality = 'LOKAL' THEN '03'
        WHEN sh.quality = 'KW4' THEN '04'
        WHEN sh.quality = 'KW5' THEN '05'
        WHEN sh.quality = 'KW6' THEN '06'
      END AS quality
      FROM tbl_sp_mutasi_pallet mp
      JOIN tbl_sp_hasilbj sh ON (mp.pallet_no = sh.pallet_no)
      WHERE substring(mp.no_mutasi from 1 for 3) IN('BAM') AND (mp.status_mut = 'S' OR mp.status_mut = 'R') $where2 $whsubplan
    ) AS z
    GROUP BY item_kode, quality";

  $query2 = pg_query($conn, $sql2);

  $arr_nilai_sale = array();
  while($r2 = pg_fetch_array($query2)) {
    $ada_ukuran[$r2[item_kode]][$r2[quality]] = '';
    $arr_nilai_sale[$r2[item_kode]][$r2[quality]] = intval($r2[jml_sale]);
  }

  $sql4 = "SELECT item_kode, quality, sum(qty)*-1 AS jml_sale
    FROM (
      SELECT substring(sh.item_kode from 1 for 2) AS item_kode, mp.qty,
      CASE
        WHEN sh.quality = 'EXPORT' THEN '01'
        WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
        WHEN sh.quality = 'LOKAL' THEN '03'
        WHEN sh.quality = 'KW4' THEN '04'
        WHEN sh.quality = 'KW5' THEN '05'
        WHEN sh.quality = 'KW6' THEN '06'
      END AS quality
      FROM tbl_sp_mutasi_pallet mp
      JOIN tbl_sp_hasilbj sh ON (mp.pallet_no = sh.pallet_no)
      WHERE substring(mp.no_mutasi from 1 for 3) IN('BAL') AND (mp.status_mut = 'S' OR mp.status_mut = 'R') $where2 $whsubplan
    ) AS z
    GROUP BY item_kode, quality";

  $query4 = pg_query($conn, $sql4);

  $arr_nilai_lokal_sale = array();
  while($r4 = pg_fetch_array($query4)) {
    $ada_ukuran[$r4[item_kode]][$r4[quality]] = '';
    $arr_nilai_lokal_sale[$r4[item_kode]][$r4[quality]] = intval($r4[jml_sale]);
  }

  $sql3 = "SELECT item_kode, quality, sum(qty)*-1 AS jml_foc
    FROM (
      SELECT substring(sh.item_kode from 1 for 2) AS item_kode, mp.qty,
      CASE
        WHEN sh.quality = 'EXPORT' THEN '01'
        WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
        WHEN sh.quality = 'LOKAL' THEN '03'
        WHEN sh.quality = 'KW4' THEN '04'
        WHEN sh.quality = 'KW5' THEN '05'
        WHEN sh.quality = 'KW6' THEN '06'
      END AS quality
      FROM tbl_sp_mutasi_pallet mp
      JOIN tbl_sp_hasilbj sh ON (mp.pallet_no = sh.pallet_no)
      WHERE (
        substring(mp.no_mutasi from 1 for 3) IN('FOC','PBP','BRP','BRR','SMP') 
        OR (substring(mp.no_mutasi from 1 for 3) IN('BAM','BAL') AND mp.status_mut = 'L')
        OR (substring(mp.no_mutasi from 1 for 3) IN('BAM','BAL') AND mp.status_mut = 'F')
      )
      $where2 $whsubplan
    ) AS z
    GROUP BY item_kode, quality";

  $query3 = pg_query($conn, $sql3);

  $arr_nilai_foc = array();
  while($r3 = pg_fetch_array($query3)) {
    $ada_ukuran[$r3[item_kode]][$r3[quality]] = '';
    $arr_nilai_foc[$r3[item_kode]][$r3[quality]] = intval($r3[jml_foc]);
  }

  $sql0 = "SELECT 
    CASE
      WHEN sh.motif_dimension = '20 X 20' THEN '05' 
      WHEN sh.motif_dimension = '30 X 30' THEN '06'
      WHEN sh.motif_dimension = '40 X 40' THEN '07'
      WHEN sh.motif_dimension = '20 X 25' THEN '08'
      WHEN sh.motif_dimension = '25 X 40' THEN '09'
      WHEN sh.motif_dimension = '25 X 25' THEN '10'
      WHEN sh.motif_dimension = '50 X 50' THEN '11'
      WHEN sh.motif_dimension = '25 X 50' THEN '12'
      WHEN sh.motif_dimension = '60 X 60' THEN '13'
    END AS item_kode, 
    CASE
      WHEN sh.quality = 'EXPORT' THEN '01'
      WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
      WHEN sh.quality = 'LOKAL' THEN '03'
      WHEN sh.quality = 'KW4' THEN '04'
      WHEN sh.quality = 'KW5' THEN '05'
      WHEN sh.quality = 'KW6' THEN '06'
    END AS quality,
    SUM(sh.prod_initial_quantity + sh.manual_initial_quantity 
         + sh.in_mut_quantity - out_mut_quantity 
         + sh.in_adjusted_quantity - sh.out_adjusted_quantity 
         - sh.returned_quantity
         - sh.broken_quantity
         - sh.sales_in_progress_quantity
         - sh.sales_confirmed_quantity
         - sh.foc_quantity
         - sh.sample_quantity
         + sh.in_downgrade_quantity
         - sh.out_downgrade_quantity) AS initial_quantity
    FROM gbj_report.summary_mutation_by_motif_size_shading sh
    WHERE sh.mutation_date < '{$where3}' $whsubplan
    GROUP BY motif_dimension, quality";
  $query0 = pg_query($conn, $sql0);
  while($r0 = pg_fetch_array($query0)) {
    $ada_ukuran[$r0[item_kode]][$r0[quality]] = '';
    $arr_saldo_awal[$r0[item_kode]][$r0[quality]] = intval($r0[initial_quantity]); 
  }

  ksort($ada_ukuran);
  reset($ada_ukuran);
  $i = 0;
  foreach ($ada_ukuran as $ukuran => $a_quality) {
    ksort($a_quality);
    reset($a_quality);
    foreach ($a_quality as $quality => $value) {
      if($quality && ($arr_nilai_prod[$ukuran][$quality] || $arr_nilai_sale[$ukuran][$quality] || $arr_nilai_lokal_sale[$ukuran][$quality] || $arr_nilai_foc[$ukuran][$quality])) {
        $kategori[] = $arr_ukuran[$ukuran]." ".$arr_quality[$quality];
        $nilai_prod[$i] = $arr_nilai_prod[$ukuran][$quality] ? $arr_nilai_prod[$ukuran][$quality] : 0;
        $nilai_sale[$i] = $arr_nilai_sale[$ukuran][$quality] ? $arr_nilai_sale[$ukuran][$quality] : 0;
        $nilai_lokal_sale[$i] = $arr_nilai_lokal_sale[$ukuran][$quality] ? $arr_nilai_lokal_sale[$ukuran][$quality] : 0;
        $nilai_foc[$i] = $arr_nilai_foc[$ukuran][$quality] ? $arr_nilai_foc[$ukuran][$quality] : 0;
        $saldo_awal[$i] = $arr_saldo_awal[$ukuran][$quality] ? $arr_saldo_awal[$ukuran][$quality] : 0;
        $i++;
      }
    }  
  }
  for ($k=0; $k < $i; $k++) { 
    $saldo_akhir[$k] = $saldo_awal[$k] + $nilai_prod[$k] - ($nilai_sale[$k] + $nilai_lokal_sale[$k] + $nilai_foc[$k]);
  }
  $responce->msg = $sql3;
  $responce->kategori = $kategori;
  $responce->data_a[0]['name'] = 'Produksi';
  $responce->data_a[0]['data'] = $nilai_prod;
  $responce->data_a[0]['color'] = 'red';
  $responce->data_a[1]['name'] = 'Sales Marketing';
  $responce->data_a[1]['data'] = $nilai_sale;
  $responce->data_a[1]['color'] = 'green';
  $responce->data_a[2]['name'] = 'Sales Lokal';
  $responce->data_a[2]['data'] = $nilai_lokal_sale;
  $responce->data_a[2]['color'] = 'blue';
  $responce->data_a[3]['name'] = 'Pecah + FOC + Sample';
  $responce->data_a[3]['data'] = $nilai_foc;
  $responce->data_a[3]['color'] = 'orange';
  $responce->saldo_awal = $saldo_awal;
  $responce->saldo_akhir = $saldo_akhir;
  $responce->kapasitas_gudang = $arr_kapasitas_gudang[$app_plan_id];
  echo json_encode($responce);
}

function mutasibykode($kodemutasi) {
  global $conn, $arr_ukuran, $arr_quality, $arr_bln;

  $jns = $_GET['jns'];
  $bln = $_GET['bln'];
  $thn = $_GET['thn'];
  $subplan = $_GET['sub'];

  $whsubplan = ($subplan == '' || strtolower($subplan) == 'all') ? "" : " AND sh.subplant = '{$subplan}' ";

  if($jns == 'B') {
    $sql = "SELECT item_kode, quality, bln, sum(qty)*-1 AS jml
      FROM (
        SELECT date_part('month', mp.create_date) AS bln, substring(sh.item_kode from 1 for 2) AS item_kode, mp.qty,
        CASE
          WHEN sh.quality = 'EXPORT' THEN '01'
          WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
          WHEN sh.quality = 'LOKAL' THEN '03'
          WHEN sh.quality = 'KW4' THEN '04'
          WHEN sh.quality = 'KW5' THEN '05'
          WHEN sh.quality = 'KW6' THEN '06'
        END AS quality
        FROM tbl_sp_mutasi_pallet mp
        JOIN tbl_sp_hasilbj sh ON (mp.pallet_no = sh.pallet_no)
        WHERE substring(mp.no_mutasi from 1 for 3) IN($kodemutasi) AND date_part('year', mp.create_date) = {$thn} $whsubplan
      ) AS z
      GROUP BY item_kode, quality, bln
      ORDER BY item_kode, quality DESC, bln";
    $query = pg_query($conn, $sql);

    $arr_nilai = array();
    while($r = pg_fetch_array($query)) {
      $ada_ukuran[$r[item_kode]][$r[quality]] = '';
      $arr_nilai[$r[item_kode]][$r[quality]][$r[bln]] = intval($r[jml]);
    }
    $arr_kategori = $arr_bln;
  } else if($jns == 'H') {
    $sql = "SELECT item_kode, quality, hari, sum(qty)*-1 AS jml
      FROM (
        SELECT date_part('day', mp.create_date) AS hari, substring(sh.item_kode from 1 for 2) AS item_kode, mp.qty,
        CASE
          WHEN sh.quality = 'EXPORT' THEN '01'
          WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
          WHEN sh.quality = 'LOKAL' THEN '03'
          WHEN sh.quality = 'KW4' THEN '04'
          WHEN sh.quality = 'KW5' THEN '05'
          WHEN sh.quality = 'KW6' THEN '06'
        END AS quality
        FROM tbl_sp_mutasi_pallet mp
        JOIN tbl_sp_hasilbj sh ON (mp.pallet_no = sh.pallet_no)
        WHERE substring(mp.no_mutasi from 1 for 3) IN($kodemutasi) AND date_part('year', mp.create_date) = {$thn} AND date_part('month', mp.create_date) = {$bln} $whsubplan
      ) AS z
      GROUP BY item_kode, quality, hari
      ORDER BY item_kode, quality DESC, hari";
    $query = pg_query($conn, $sql);
    $arr_nilai = array();
    while($r = pg_fetch_array($query)) {
      $ada_ukuran[$r[item_kode]][$r[quality]] = '';
      $ada_hari[$r[hari]] = $r[hari];
      $arr_nilai[$r[item_kode]][$r[quality]][$r[hari]] = intval($r[jml]);
    }
    ksort($ada_hari);
    reset($ada_hari);
    $arr_kategori = $ada_hari;
  } else if($jns == 'T') {
    $sql = "SELECT item_kode, quality, tahun, sum(qty)*-1 AS jml
      FROM (
        SELECT date_part('year', mp.create_date) AS tahun, substring(sh.item_kode from 1 for 2) AS item_kode, mp.qty,
        CASE
          WHEN sh.quality = 'EXPORT' THEN '01'
          WHEN sh.quality = 'ECONOMY' OR sh.quality = 'EKONOMI' THEN '02'
          WHEN sh.quality = 'LOKAL' THEN '03'
          WHEN sh.quality = 'KW4' THEN '04'
          WHEN sh.quality = 'KW5' THEN '05'
          WHEN sh.quality = 'KW6' THEN '06'
        END AS quality
        FROM tbl_sp_mutasi_pallet mp
        JOIN tbl_sp_hasilbj sh ON (mp.pallet_no = sh.pallet_no)
        WHERE substring(mp.no_mutasi from 1 for 3) IN($kodemutasi) $whsubplan
      ) AS z
      GROUP BY item_kode, quality, tahun
      ORDER BY item_kode, quality DESC, tahun";
    $query = pg_query($conn, $sql);
    $arr_nilai = array();
    while($r = pg_fetch_array($query)) {
      $ada_ukuran[$r[item_kode]][$r[quality]] = '';
      $ada_tahun[$r[tahun]] = $r[tahun];
      $arr_nilai[$r[item_kode]][$r[quality]][$r[tahun]] = intval($r[jml]);
    }
    ksort($ada_tahun);
    reset($ada_tahun);
    $arr_kategori = $ada_tahun;
  }

  foreach ($arr_kategori as $kat => $kat_lbl) {
    $kategori[] = $kat_lbl;  
  }
  foreach ($ada_ukuran as $ukuran => $a_quality) {
    foreach ($a_quality as $quality => $value) {
      if($quality) {
        $i = 0;
        foreach ($arr_kategori as $kat => $value2) {
          if($arr_nilai[$ukuran][$quality][$kat]) {
            $arr_baris[$ukuran][$quality] = $arr_ukuran[$ukuran].' '.$arr_quality[$quality];
            $nilai[$ukuran][$quality][$i] = $arr_nilai[$ukuran][$quality][$kat];
          } else {
            $nilai[$ukuran][$quality][$i] = 0;
          }
          $i++;
        }
      }
    }  
  }
  $i = 0;
  ksort($arr_baris);
  reset($arr_baris);
  foreach ($arr_baris as $ukuran => $a_quality) {
    ksort($a_quality);
    reset($a_quality);
    foreach ($a_quality as $quality => $lbl) {
      $responce->data_a[$i]['name'] = $lbl;
      $responce->data_a[$i]['data'] = $nilai[$ukuran][$quality];
      $i++;
    } 
  }
  $responce->kategori = $kategori;
  return $responce;
}

function barangpecah() {
  $responce = mutasibykode("'PBP','BRP','BRR'");
  echo json_encode($responce);
}

function foc() {
  $responce = mutasibykode("'FOC'");
  echo json_encode($responce);
}

function aging() {
  global $conn, $arr_ukuran, $arr_quality;

  $jns = $_GET['jns'];
  $tgl = $_GET['tgl'];
  $tglb = $_GET['tglb'];
  $vuk = $_GET['uk'];

  $arr_color = array('blue','red','green','purple','cornflowerblue','chocolate','crimson','lawngreen','indigo');
  $arr_tanggal = array();
  $tanggal = date_create($tgl);
  $tanggalb = date_create($tglb);
  $diff = date_diff($tanggal, $tanggalb);
  $jmlhari = $diff->format("%a");
  $arr_tanggal[date_format($tanggal,"Y-m-d")] = '';
  for ($i=1; $i <=$jmlhari ; $i++) { 
    date_add($tanggal,date_interval_create_from_date_string("1 days"));
    $arr_tanggal[date_format($tanggal,"Y-m-d")] = '';
  }
  ksort($arr_tanggal);
  reset($arr_tanggal);
  $arr_nilai = array();
  $lbl_ukuran = '';
  $where1 = '';
  if($vuk <> 'all') {
    $where1 = " AND substring(tbl_sp_hasilbj.item_kode from 1 for 2) = '{$vuk}' ";
    $lbl_ukuran = ' UK '.$arr_ukuran[$vuk];
  }
  if($jns == 'A') {
    foreach ($arr_tanggal as $tanggal => $value) {
      $kategori[] = $tanggal;  
      $sql = "SELECT a.tgl, a.age, SUM(a.last_qty) as jml
        FROM (
          SELECT '{$tanggal}' AS tgl,
          CASE
            WHEN ('{$tanggal}' - tbl_sp_hasilbj.create_date) >= 121 AND ('{$tanggal}' - tbl_sp_hasilbj.create_date) <= 150 THEN 'E. 121-150 days'::text
            WHEN ('{$tanggal}' - tbl_sp_hasilbj.create_date) >= 151 AND ('{$tanggal}' - tbl_sp_hasilbj.create_date) <= 180 THEN 'F. 151-180 days'::text
            WHEN ('{$tanggal}' - tbl_sp_hasilbj.create_date) >= 181 AND ('{$tanggal}' - tbl_sp_hasilbj.create_date) <= 210 THEN 'G. 181-210 days'::text
            WHEN ('{$tanggal}' - tbl_sp_hasilbj.create_date) >= 211 AND ('{$tanggal}' - tbl_sp_hasilbj.create_date) <= 240 THEN 'H. 211-240 days'::text
            WHEN ('{$tanggal}' - tbl_sp_hasilbj.create_date) >= 241 THEN 'I. >240 days'::text
          END AS age,
          tbl_sp_hasilbj.last_qty
          FROM tbl_sp_hasilbj
          WHERE tbl_sp_hasilbj.last_qty > 0 AND tbl_sp_hasilbj.status_plt IN ('R','B','K') $where1
        ) AS a
        WHERE a.age IS NOT null
        GROUP BY a.tgl, a.age
        ORDER BY a.age";
      $query = pg_query($conn, $sql);
      while($r = pg_fetch_array($query)) {
        $ada_age[$r[age]] = '';
        $arr_nilai[$r[age]][$r[tgl]] = intval($r[jml]);
      }
    }

    $responce->kategori = $kategori;

    foreach ($ada_age as $age => $value) {
      $i = 0;
      foreach ($arr_tanggal as $tanggal => $value2) {
        $nilai[$age][$i] = $arr_nilai[$age][$tanggal] ? $arr_nilai[$age][$tanggal] : 0;
        $i++;
      }  
    }

    $i = 0;
    ksort($ada_age);
    reset($ada_age);
    foreach ($ada_age as $age => $age_lbl) {
      $responce->data_a[$i]['name'] = $age;
      $responce->data_a[$i]['color'] = $arr_color[$i];
      $responce->data_a[$i]['data'] = $nilai[$age];
      $i++;
    }
  } else if($jns == 'U') {
    foreach ($arr_tanggal as $tanggal => $value) {
      $kategori[] = $tanggal;  
      $sql = "SELECT a.tgl, a.ukuran, SUM(a.last_qty) as jml
        FROM (
          SELECT '{$tanggal}' AS tgl, substring(tbl_sp_hasilbj.item_kode from 1 for 2) AS ukuran, tbl_sp_hasilbj.last_qty
          FROM tbl_sp_hasilbj
          WHERE tbl_sp_hasilbj.last_qty > 0 AND tbl_sp_hasilbj.status_plt IN ('R','B','K') $where1
          AND ('{$tanggal}' - tbl_sp_hasilbj.create_date) >= 121
        ) AS a
        GROUP BY a.tgl, a.ukuran
        ORDER BY a.ukuran";
      $query = pg_query($conn, $sql);
      while($r = pg_fetch_array($query)) {
        $ada_ukuran[$r[ukuran]] = '';
        $arr_nilai[$r[ukuran]][$r[tgl]] = intval($r[jml]);
      }
    }

    $responce->kategori = $kategori;

    foreach ($ada_ukuran as $ukuran => $value) {
      $ada_ukuran[$ukuran] = $arr_ukuran[$ukuran];
      $i = 0;
      foreach ($arr_tanggal as $tanggal => $value2) {
        $nilai[$ukuran][$i] = $arr_nilai[$ukuran][$tanggal] ? $arr_nilai[$ukuran][$tanggal] : 0;
        $i++;
      }  
    }

    $i = 0;
    ksort($ada_ukuran);
    reset($ada_ukuran);
    foreach ($ada_ukuran as $ukuran => $ukuran_lbl) {
      $responce->data_a[$i]['name'] = $ukuran_lbl;
      $responce->data_a[$i]['color'] = $arr_color[$i];
      $responce->data_a[$i]['data'] = $nilai[$ukuran];
      $i++;
    }
  }

  $responce->judul = 'GRAFIK AGING >120 HARI'.$lbl_ukuran;
  $responce->sql = $sql;   
  echo json_encode($responce);
}

?>