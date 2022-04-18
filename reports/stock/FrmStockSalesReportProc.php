<?php
require_once("../../include/koneksi.inc.php");
//error_reporting(E_ALL ^ E_NOTICE);
//require_once("class_php/class_mssql.php");
//ob_clean();
//$con=odbc_connect($DB, $User, $Pass);
global $mode,$con,$stat,$db;
switch ($mode) {
	
		case "viewendpallet":
			viewendpallet();
			break;
		case "viewrekhtml":
			cetakrek();
			break;
		case "viewdethtml":
			viewdethtml();
			break;		
		
		case "viewfoc":
			viewfoc();
			break;		

		case "viewsample":
			viewsample();
			break;				

		case "viewPecah":
			viewPecah();
			break;		
}

function viewendpallet(){
//require_once("include/koneksi.inc.php");
global $db;

$tgl2=$_GET['tgl2'];

echo "<script>";
echo "function printContent(){";
echo "var restorepage = document.body.innerHTML;";
echo "var printcontent = document.getElementById('div1').innerHTML;";
echo "document.body.innerHTML = printcontent;";
echo "window.print();";
echo "document.body.innerHTML = restorepage;";
echo "}";
echo "</script>";
echo "<button onclick='printContent()'>PRINT</button>";
echo "<div id='div1' style='margin-left: 5px;'>";
//echo $tp;
$html = '<table border="0" cellspacing="0" cellpadding="0" width="100%">
	<tr><td align="center" colspan="6"> <font size="4"><strong>DAFTAR SISA PALLET</strong></td></tr>
	<tr><td align="left" colspan="6" >Periode : '.$tgl2.'</td></tr>
	
	<tr><td align="left" colspan="6"><font size="2">
	<table border="1" cellspacing="0" cellpadding="3" width="100%">
					<tr bgcolor="b6e6bd">
					<td width="4%" align="center"><strong>No.</strong></td>
					<td width="4%" align="center"><strong>Plant</strong></td>
					<td width="15%" align="center"><strong>Pallet</strong></td>
					<td width="15%" align="center"><strong>Motif Id</strong></td>
					<td width="40%" align="center"><strong>Motif</strong></td>
					<td width="7%" align="center"><strong>Quality</strong></td>
					<td width="5%" align="center"><strong>Size</strong></td>
					<td width="5%" align="center"><strong>Shade</strong></td>
					<td width="5%" align="right"><strong>Qty</strong></td>
					</tr>';

$i=1;
$totqty=0;
//echo $sql;


$sql="select a.subplant,a.pallet_no,c.item_kode,b.item_nama,c.quality,c.size,c.shade,sum(a.quantity) as qty 
from gbj_report.mutation_records a
left outer join item b on motif_id=item_kode
left outer join tbl_sp_hasilbj c on a.pallet_no=c.pallet_no
where mutation_time::date<='$tgl2'
group by a.subplant,a.pallet_no,c.item_kode,b.item_nama,c.quality,c.size,c.shade
having sum(quantity)<>0;";

$sql="select a.subplant,a.pallet_no,b.item_kode,c.item_nama,c.quality,b.size,b.shade,sum(a.quantity) as qty 
from gbj_report.mutation_records a
left outer join tbl_sp_hasilbj b on a.pallet_no=b.pallet_no
left outer join item c on b.item_kode=c.item_kode
where mutation_time::date<='$tgl2'
group by a.subplant,a.pallet_no,b.item_kode,c.item_nama,c.quality,b.size,b.shade
having sum(quantity)<>0;";


$nosj="";

$query=pg_query($db,$sql);
	while($row=pg_fetch_array($query)){
		if($row['quality'] == "KW4") {
            $row['quality'] =  "LOKAL";
            $row['item_nama'] = str_replace("KW4","LOKAL",$row['item_nama']);
        } else if($row['quality'] == "KW5") {
            $row['quality'] =  "BBM SQUARING";
            $row['item_nama'] = str_replace("KW5","BBM SQUARING",$row['item_nama']);
        } else if($row['quality'] == "KW6") {
            $row['quality'] =  "BBM OVERSIZE";
            $row['item_nama'] = str_replace("KW6","BBM OVERSIZE",$row['item_nama']);
        }
		$html.= '<tr ><td align="center"> '.$i.'</td>
					<td align="center">'.$row['subplant'].'</td>
					<td align="center">'.$row['pallet_no'].'</td>		
					<td align="center">'.$row['item_kode'].'</td>
					<td align="left">'.$row['item_nama'].'</td>
					<td align="center">'.$row['quality'].'</td>
					<td align="center">'.$row['size'].'</td>
					<td align="center">'.$row['shade'].'</td>
					<td align="right">'.$row['qty'].'</td>
					</tr>';
		$i++;					
		$totqty=$totqty+$row['qty'];
}

$html.= '<tr><td colspan="8"></td><td align="right">'.number_format($totqty).'</td></tr>';
$html.= '</table></font></td></tr></table>';
echo $html;
echo "</div>";
}

function viewPecah(){
//require_once("include/koneksi.inc.php");
global $db;

$tgl1=$_GET['tgl1'];
$tgl2=$_GET['tgl2'];

echo "<script>";
echo "function printContent(){";
echo "var restorepage = document.body.innerHTML;";
echo "var printcontent = document.getElementById('div1').innerHTML;";
echo "document.body.innerHTML = printcontent;";
echo "window.print();";
echo "document.body.innerHTML = restorepage;";
echo "}";
echo "</script>";
echo "<button onclick='printContent()'>PRINT</button>";
echo "<div id='div1' style='margin-left: 5px;'>";
//echo $tp;
$html = '<table border="0" cellspacing="0" cellpadding="0" width="100%">
	<tr><td align="center" colspan="6"> <font size="4"><strong>LAPORAN BARANG PECAH</strong></td></tr>
	<tr><td align="left" colspan="6" >Periode : '.$tgl1.' s/d '.$tgl2.'</td></tr>
	
	<tr><td align="left" colspan="6"><font size="2">
	<table border="1" cellspacing="0" cellpadding="3" width="100%">
					<tr bgcolor="b6e6bd">
					<td width="4%" align="center"><strong>No.</strong></td>
					<td width="10%" align="center"><strong>No.Transaksi</strong></td>
					<td width="8%" align="center"><strong>Tanggal</strong></td>
					<td width="8%" align="center"><strong>Tgl Inp</strong></td>
					<td width="4%" align="center"><strong>Plant</strong></td>
					<td width="12%" align="center"><strong>Pallet</strong></td>
					<td width="12%" align="center"><strong>Motif Id</strong></td>
					<td width="22%" align="center"><strong>Motif</strong></td>
					<td width="5%" align="center"><strong>Quality</strong></td>
					<td width="5%" align="center"><strong>Size</strong></td>
					<td width="5%" align="center"><strong>Shade</strong></td>
					<td width="5%" align="right"><strong>Qty</strong></td>
					</tr>';

$i=1;
$totqty=0;
//echo $sql;

$sql="select a.retur_kode as no_mutasi,a.tanggal,e.create_date::date as exedate,c.pallet_no,c.subplant,c.item_kode,d.item_nama,
c.quality,shade,size,abs(b.export)as qty,b.keterangan,a.jenis_bahan
from tbl_retur_produksi a 
inner join item_retur_produksi b on a.retur_kode=b.retur_kode 
inner join tbl_sp_hasilbj c on b.pallet_no=c.pallet_no inner join item d on d.item_kode=c.item_kode
inner join tbl_sp_mutasi_pallet e on b.retur_kode=e.no_mutasi and b.pallet_no=e.pallet_no
where left(a.retur_kode,3)in ('BRP','PBP') AND e.create_date::date>='".$tgl1."' and e.create_date::date<='".$tgl2."'
order by a.retur_kode;";



$nosj="";

$query=pg_query($db,$sql);
	while($row=pg_fetch_array($query)){
	
if($nosj==$row['no_mutasi']){
	$html.= '<tr >	<td align="center"></td>
					<td align="center"></td>
					<td align="center"></td>
					<td align="center"></td>';
	
} else {
	$nosj=$row['no_mutasi'];
		$html.= '<tr ><td align="center"> '.$i.'</td>
					<td align="center">'.$row['no_mutasi'].'</td>
					<td align="center">'.$row['tanggal'].'</td>
					<td align="center">'.$row['exedate'].'</td>					';
	$i++;
}
		if($row['quality'] == "KW4") {
            $row['quality'] =  "LOKAL";
            $row['item_nama'] = str_replace("KW4","LOKAL",$row['item_nama']);
        } else if($row['quality'] == "KW5") {
            $row['quality'] =  "BBM SQUARING";
            $row['item_nama'] = str_replace("KW5","BBM SQUARING",$row['item_nama']);
        } else if($row['quality'] == "KW6") {
            $row['quality'] =  "BBM OVERSIZE";
            $row['item_nama'] = str_replace("KW6","BBM OVERSIZE",$row['item_nama']);
        }
		$html.= '	<td align="center">'.$row['subplant'].'</td>
					<td align="center">'.$row['pallet_no'].'</td>		
					<td align="center">'.$row['item_kode'].'</td>
					<td align="left">'.$row['item_nama'].'</td>
					<td align="center">'.$row['quality'].'</td>
					<td align="center">'.$row['size'].'</td>
					<td align="center">'.$row['shade'].'</td>
					<td align="right">'.$row['qty'].'</td>
					</tr>';
		
		$totqty=$totqty+$row['qty'];
}
$html.= '<tr><td colspan="11"></td><td align="right">'.number_format($totqty).'</td></tr>';
$html.= '</table></font></td></tr></table>';
echo $html;
echo "</div>";
}

function viewsample(){
//require_once("include/koneksi.inc.php");
global $db;

$tgl1=$_GET['tgl1'];
$tgl2=$_GET['tgl2'];

echo "<script>";
echo "function printContent(){";
echo "var restorepage = document.body.innerHTML;";
echo "var printcontent = document.getElementById('div1').innerHTML;";
echo "document.body.innerHTML = printcontent;";
echo "window.print();";
echo "document.body.innerHTML = restorepage;";
echo "}";
echo "</script>";
echo "<button onclick='printContent()'>PRINT</button>";
echo "<div id='div1' style='margin-left: 5px;'>";
//echo $tp;
$html = '<table border="0" cellspacing="0" cellpadding="0" width="100%">
	<tr><td align="center" colspan="6"> <font size="4"><strong>LAPORAN SAMPLE</strong></td></tr>
	<tr><td align="left" colspan="6" >Periode : '.$tgl1.' s/d '.$tgl2.'</td></tr>
	
	<tr><td align="left" colspan="6"><font size="2">
	<table border="1" cellspacing="0" cellpadding="3" width="100%">
	<tr bgcolor="b6e6bd">
					<td width="4%" align="center"><strong>No.</strong></td>
					<td width="10%" align="center"><strong>No.Transaksi</strong></td>
					<td width="8%" align="center"><strong>Tanggal</strong></td>
					<td width="8%" align="center"><strong>Tgl Inp</strong></td>
					<td width="4%" align="center"><strong>Plant</strong></td>
					<td width="12%" align="center"><strong>Pallet</strong></td>
					<td width="12%" align="center"><strong>Motif Id</strong></td>
					<td width="22%" align="center"><strong>Motif</strong></td>
					<td width="5%" align="center"><strong>Quality</strong></td>
					<td width="5%" align="center"><strong>Size</strong></td>
					<td width="5%" align="center"><strong>Shade</strong></td>
					<td width="5%" align="right"><strong>Qty</strong></td>
					</tr>';

$i=1;
$totqty=0;
//echo $sql;
$sql = "select a.no_mutasi,a.tanggal,a.create_date::date as exedate,a.pallet_no,b.subplant,b.item_kode,c.item_nama,b.quality,b.shade,b.size,abs(a.qty) as qty
		from tbl_sp_mutasi_pallet a
		inner join tbl_sp_hasilbj b on a.pallet_no=b.pallet_no
		inner join item c on b.item_kode=c.item_kode 
		where (a.no_mutasi like 'SMP/%') and a.create_date>='".$tgl1." 00:00' and a.create_date<='".$tgl2." 23:59' 
		order by a.no_mutasi;";
//echo $sql;

$nosj="";

$query=pg_query($db,$sql);
	while($row=pg_fetch_array($query)){
	
if($nosj==$row['no_mutasi']){
	$html.= '<tr >	<td align="center"></td>
					<td align="center"></td>
					<td align="center"></td>
					<td align="center"></td>';
	
} else {
	$nosj=$row['no_mutasi'];
		$html.= '<tr ><td align="center"> '.$i.'</td>
					<td align="center">'.$row['no_mutasi'].'</td>
					<td align="center">'.$row['tanggal'].'</td>
					<td align="center">'.$row['exedate'].'</td>';
	$i++;
}
		if($row['quality'] == "KW4") {
            $row['quality'] =  "LOKAL";
            $row['item_nama'] = str_replace("KW4","LOKAL",$row['item_nama']);
        } else if($row['quality'] == "KW5") {
            $row['quality'] =  "BBM SQUARING";
            $row['item_nama'] = str_replace("KW5","BBM SQUARING",$row['item_nama']);
        } else if($row['quality'] == "KW6") {
            $row['quality'] =  "BBM OVERSIZE";
            $row['item_nama'] = str_replace("KW6","BBM OVERSIZE",$row['item_nama']);
        }
	
		$html.= '	<td align="center">'.$row['subplant'].'</td>
					<td align="center">'.$row['pallet_no'].'</td>		
					<td align="center">'.$row['item_kode'].'</td>
					<td align="left">'.$row['item_nama'].'</td>
					<td align="center">'.$row['quality'].'</td>
					<td align="center">'.$row['size'].'</td>
					<td align="center">'.$row['shade'].'</td>
					<td align="right">'.$row['qty'].'</td>
					</tr>';
		
		$totqty=$totqty+$row['qty'];
}
$html.= '<tr><td colspan="11"></td><td align="right">'.number_format($totqty).'</td></tr>';
$html.= '</table></font></td></tr></table>';
echo $html;
echo "</div>";
}

function viewfoc(){
//require_once("include/koneksi.inc.php");
global $db;

$tgl1=$_GET['tgl1'];
$tgl2=$_GET['tgl2'];

echo "<script>";
echo "function printContent(){";
echo "var restorepage = document.body.innerHTML;";
echo "var printcontent = document.getElementById('div1').innerHTML;";
echo "document.body.innerHTML = printcontent;";
echo "window.print();";
echo "document.body.innerHTML = restorepage;";
echo "}";
echo "</script>";
echo "<button onclick='printContent()'>PRINT</button>";
echo "<div id='div1' style='margin-left: 5px;'>";
//echo $tp;
$html = '<table border="0" cellspacing="0" cellpadding="0" width="100%">
	<tr><td align="center" colspan="6"> <font size="4"><strong>LAPORAN FOC</strong></td></tr>
	<tr><td align="left" colspan="6" >Periode : '.$tgl1.' s/d '.$tgl2.'</td></tr>
	
	<tr><td align="left" colspan="6"><font size="2">
	<table border="1" cellspacing="0" cellpadding="3" width="100%">
	<tr bgcolor="b6e6bd">
					<td width="4%" align="center"><strong>No.</strong></td>
					<td width="10%" align="center"><strong>No.Transaksi</strong></td>
					<td width="8%" align="center"><strong>Tanggal</strong></td>
					<td width="8%" align="center"><strong>Tgl Inp</strong></td>
					<td width="4%" align="center"><strong>Plant</strong></td>
					<td width="12%" align="center"><strong>Pallet</strong></td>
					<td width="12%" align="center"><strong>Motif Id</strong></td>
					<td width="22%" align="center"><strong>Motif</strong></td>
					<td width="5%" align="center"><strong>Quality</strong></td>
					<td width="5%" align="center"><strong>Size</strong></td>
					<td width="5%" align="center"><strong>Shade</strong></td>
					<td width="5%" align="right"><strong>Qty</strong></td>
					</tr>';

$i=1;
$totqty=0;

//echo $sql;
$sql = "select a.no_mutasi,a.tanggal,a.create_date::date as exedate,a.pallet_no,b.subplant,b.item_kode,c.item_nama,b.quality,b.shade,b.size,abs(a.qty) as qty
		from tbl_sp_mutasi_pallet a
		inner join tbl_sp_hasilbj b on a.pallet_no=b.pallet_no
		inner join item c on b.item_kode=c.item_kode 
		where (a.no_mutasi like 'FOC/%') and a.create_date>='".$tgl1." 00:00' and a.create_date<='".$tgl2." 23:59' 
		union all
		select mutation_id,mutation_time::date,mutation_time::date,pallet_no,subplant,motif_id,item_nama,quality,shading,size,abs(quantity) as qty from gbj_report.mutation_records 
		INNER join item on item_kode=motif_id
		where mutation_type='FOC'
		AND mutation_time::date>='$tgl1' and mutation_time::date<='$tgl2'
		and left(mutation_id,3)='BAL'
		order by no_mutasi;";
//echo $sql;
$nosj="";

$query=pg_query($db,$sql);
	while($row=pg_fetch_array($query)){
	
if($nosj==$row['no_mutasi']){
	$html.= '<tr >	<td align="center"></td>
					<td align="center"></td>
					<td align="center"></td>
					<td align="center"></td>';
	
} else {
	$nosj=$row['no_mutasi'];
		$html.= '<tr ><td align="center"> '.$i.'</td>
					<td align="center">'.$row['no_mutasi'].'</td>
					<td align="center">'.$row['tanggal'].'</td>
					<td align="center">'.$row['exedate'].'</td>';
	$i++;
}
		if($row['quality'] == "KW4") {
            $row['quality'] =  "LOKAL";
            $row['item_nama'] = str_replace("KW4","LOKAL",$row['item_nama']);
        } else if($row['quality'] == "KW5") {
            $row['quality'] =  "BBM SQUARING";
            $row['item_nama'] = str_replace("KW5","BBM SQUARING",$row['item_nama']);
        } else if($row['quality'] == "KW6") {
            $row['quality'] =  "BBM OVERSIZE";
            $row['item_nama'] = str_replace("KW6","BBM OVERSIZE",$row['item_nama']);
        }
	
		$html.= '	<td align="center">'.$row['subplant'].'</td>
					<td align="center">'.$row['pallet_no'].'</td>		
					<td align="center">'.$row['item_kode'].'</td>
					<td align="left">'.$row['item_nama'].'</td>
					<td align="center">'.$row['quality'].'</td>
					<td align="center">'.$row['size'].'</td>
					<td align="center">'.$row['shade'].'</td>
					<td align="right">'.$row['qty'].'</td>
					</tr>';
		
		$totqty=$totqty+$row['qty'];
}
$html.= '<tr><td colspan="11"></td><td align="right">'.number_format($totqty).'</td></tr>';
$html.= '</table></font></td></tr></table>';
echo $html;
echo "</div>";
}

function viewdethtml(){
//require_once("include/koneksi.inc.php");
global $db;

$tgl1=$_GET['tgl1'];
$tgl2=$_GET['tgl2'];

echo "<script>";
echo "function printContent(){";
echo "var restorepage = document.body.innerHTML;";
echo "var printcontent = document.getElementById('div1').innerHTML;";
echo "document.body.innerHTML = printcontent;";
echo "window.print();";
echo "document.body.innerHTML = restorepage;";
echo "}";
echo "</script>";
echo "<button onclick='printContent()'>PRINT</button>";
echo "<div id='div1' style='margin-left: 5px;'>";
//echo $tp;
$html = '<table border="0" cellspacing="0" cellpadding="0" width="100%">
	<tr><td align="center" colspan="6"> <font size="4"><strong>LAPORAN PENJUALAN DETAIL</strong></td></tr>
	<tr><td align="left" colspan="6" >Periode : '.$tgl1.' s/d '.$tgl2.'</td></tr>
	
	<tr><td align="left" colspan="6"><font size="2">
	<table border="1" cellspacing="0" cellpadding="3" width="100%">
				<tr bgcolor="b6e6bd">
					<td width="3%" align="center"><strong>No.</strong></td>
					<td width="8%" align="center"><strong>No.Sj</strong></td>
					<td width="7%" align="center"><strong>Tgl SJ</strong></td>
					<td width="8%" align="center"><strong>Tgl Inp</strong></td>
					<td width="10%" align="center"><strong>No.Muat</strong></td>
					<td width="4%" align="center"><strong>Plant</strong></td>
					<td width="12%" align="center"><strong>Pallet</strong></td>
					
					<td width="10%" align="center"><strong>Motif Id</strong></td>
					<td width="18%" align="center"><strong>Motif</strong></td>
					<td width="5%" align="center"><strong>Quality</strong></td>
					<td width="5%" align="center"><strong>Size</strong></td>
					<td width="5%" align="center"><strong>Shade</strong></td>
					<td width="5%" align="right"><strong>Qty</strong></td>
					</tr>';

$i=1;
$totqty=0;
$sql = "
                SELECT
                    ref_txn_id,
					(select tanggal from tbl_surat_jalan where no_surat_jalan=ref_txn_id) as sjdate,
                    mutation_time :: DATE AS mutation_date,
					mutation_id,
                    subplant,
					motif_id,
					pallet_no,
					item_nama,
					left(quality,3) as quality,
                    size,
                    shading,
                    abs(quantity) as quantity
                FROM gbj_report.mutation_records_adjusted t1
				left outer join item t2 on t1.motif_id=t2.item_kode
                WHERE 
                  mutation_time BETWEEN '".$tgl1." 00:00:00' AND '".$tgl2." 23:59:59'
                  AND mutation_type IN ('BAM', 'BAL', 'JSP')
                  AND ref_txn_id IS NOT NULL
                ORDER BY sjdate,ref_txn_id,mutation_id,subplant,motif_id;";
//echo $sql;
$nosj="";

$query=pg_query($db,$sql);
	while($row=pg_fetch_array($query)){
	
if($nosj==$row['ref_txn_id']){
	$html.= '<tr ><td align="center"></td>
					<td align="center"></td>
					<td align="center"></td>
					<td align="center"></td>
					<td align="center"></td>';
	
} else {
	$nosj=$row['ref_txn_id'];
		$html.= '<tr ><td align="center"> '.$i.'</td>
					<td align="center">'.$row['ref_txn_id'].'</td>
					<td align="center">'.$row['sjdate'].'</td>
					<td align="center">'.$row['mutation_date'].'</td>
					<td align="center">'.$row['mutation_id'].'</td>';
	
	$i++;
}
		if($row['quality'] == "KW4") {
            $row['quality'] =  "LOKAL";
            $row['item_nama'] = str_replace("KW4","LOKAL",$row['item_nama']);
        } else if($row['quality'] == "KW5") {
            $row['quality'] =  "BBM SQUARING";
            $row['item_nama'] = str_replace("KW5","BBM SQUARING",$row['item_nama']);
        } else if($row['quality'] == "KW6") {
            $row['quality'] =  "BBM OVERSIZE";
            $row['item_nama'] = str_replace("KW6","BBM OVERSIZE",$row['item_nama']);
        }
		$html.= '<td align="center">'.$row['subplant'].'</td>
					<td align="center">'.$row['pallet_no'].'</td>
					<td align="center">'.$row['motif_id'].'</td>
					<td align="left">'.$row['item_nama'].'</td>
					<td align="center">'.$row['quality'].'</td>
					<td align="center">'.$row['size'].'</td>
					<td align="center">'.$row['shading'].'</td>
					<td align="right">'.$row['quantity'].'</td>
					</tr>';
		
		$totqty=$totqty+$row['quantity'];
}
$html.= '<tr><td colspan="12"></td><td align="right">'.number_format($totqty).'</td></tr>';
$html.= '</table></font></td></tr></table>';
echo $html;
echo "</div>";
}


function cetakrek(){
//require_once("include/koneksi.inc.php");
global $db;

$tgl1=$_GET['tgl1'];
$tgl2=$_GET['tgl2'];

echo "<script>";
echo "function printContent(){";
echo "var restorepage = document.body.innerHTML;";
echo "var printcontent = document.getElementById('div1').innerHTML;";
echo "document.body.innerHTML = printcontent;";
echo "window.print();";
echo "document.body.innerHTML = restorepage;";
echo "}";
echo "</script>";
echo "<button onclick='printContent()'>PRINT</button>";
echo "<div id='div1' style='margin-left: 5px;'>";
//echo $tp;
$html = '<table border="0" cellspacing="0" cellpadding="0" width="100%">
	<tr><td align="center" colspan="6"> <font size="4"><strong>LAPORAN PENJUALAN</strong></td></tr>
	<tr><td align="left" colspan="6" >Periode : '.$tgl1.' s/d '.$tgl2.'</td></tr>
	
	<tr><td align="left" colspan="6"><font size="2">
	<table border="1" cellspacing="0" cellpadding="3" width="100%">
					<td width="5%" align="center"><strong>No.</strong></td>
					<td width="8%" align="center"><strong>No.Sj</strong></td>
					<td width="7%" align="center"><strong>Tgl SJ</strong></td>
					<td width="8%" align="center"><strong>Tgl Inp</strong></td>
					<td width="10%" align="center"><strong>No.Muat</strong></td>
					<td width="5%" align="center"><strong>Plant</strong></td>
					<td width="10%" align="center"><strong>Motif Id</strong></td>
					<td width="24%" align="center"><strong>Motif</strong></td>
					<td width="6%" align="center"><strong>Quality</strong></td>
					<td width="5%" align="center"><strong>Size</strong></td>
					<td width="5%" align="center"><strong>Shade</strong></td>
					<td width="7%" align="right"><strong>Qty</strong></td>
					</tr>';

$i=1;
$totqty=0;
$nosj="123";
$sql = "
                SELECT
                    ref_txn_id,
					(select tanggal from tbl_surat_jalan where no_surat_jalan=ref_txn_id) as sjdate,
                    mutation_time :: DATE AS mutation_date,
					mutation_id,
                    subplant,
					motif_id,
					item_nama,
					left(quality,3) as quality,
                    size,
                    shading,
                    sum(abs(quantity)) as quantity
                FROM gbj_report.mutation_records_adjusted t1
				left outer join item t2 on t1.motif_id=t2.item_kode
                WHERE 
                  mutation_time BETWEEN '".$tgl1." 00:00:00' AND '".$tgl2." 23:59:59'
                  AND mutation_type IN ('BAM', 'BAL', 'JSP')
                  AND ref_txn_id IS NOT NULL
				  group by ref_txn_id,mutation_date,mutation_id,subplant,motif_id,item_nama,quality,size,shading
                ORDER BY sjdate,ref_txn_id,mutation_id,subplant,motif_id;";
//echo $sql;

$query=pg_query($db,$sql);
	while($row=pg_fetch_array($query)){
	
	if($nosj==$row['ref_txn_id']){
	$html.= '<tr ><td align="center"></td>
					<td align="center"></td>
					<td align="center"></td>
					<td align="center"></td>
					<td align="center"></td>';
	
} else {
		$nosj=$row['ref_txn_id'];		
		$html.= '<tr ><td align="center"> '.$i.'</td>
					<td align="center">'.$row['ref_txn_id'].'</td>
					<td align="center">'.$row['sjdate'].'</td>
					<td align="center">'.$row['mutation_date'].'</td>
					<td align="center">'.$row['mutation_id'].'</td>';
			
	$i++;
}

		if($row['quality'] == "KW4") {
            $row['quality'] =  "LOKAL";
            $row['item_nama'] = str_replace("KW4","LOKAL",$row['item_nama']);
        } else if($row['quality'] == "KW5") {
            $row['quality'] =  "BBM SQUARING";
            $row['item_nama'] = str_replace("KW5","BBM SQUARING",$row['item_nama']);
        } else if($row['quality'] == "KW6") {
            $row['quality'] =  "BBM OVERSIZE";
            $row['item_nama'] = str_replace("KW6","BBM OVERSIZE",$row['item_nama']);
        }

		$html.= '
					<td align="center">'.$row['subplant'].'</td>
					<td align="center">'.$row['motif_id'].'</td>
					<td align="left">'.$row['item_nama'].'</td>
					<td align="center">'.$row['quality'].'</td>
					<td align="center">'.$row['size'].'</td>
					<td align="center">'.$row['shading'].'</td>
					<td align="right">'.$row['quantity'].'</td>
					</tr>';
		
		$totqty=$totqty+$row['quantity'];
}
$html.= '<tr><td colspan="11"></td><td align="right">'.number_format($totqty).'</td></tr>';
$html.= '</table></font></td></tr></table>';
echo $html;
echo "</div>";
}

?>
