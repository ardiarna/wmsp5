<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Grafik Sales Vs Hasil Produksi</title>
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
  <script src="assets/libs/jquery/jquery.min.js"></script>
  <script src="assets/libs/highcharts/highcharts.js"></script>
  <script src="assets/libs/highcharts/modules/exporting.js"></script>
</head>
<body>
  <div id="kontener1" style="min-width: 180px; margin: 0 auto"></div>
  <div id="kontener2"></div>

  <script type="text/javascript">
    const FRM = 'grafik.inc.php';
    const vJns = "<?= $_GET['jns'] ?>";
    const vTgl = "<?= $_GET['tgl'] ?>";
    const vTglb = "<?= $_GET['tglb'] ?>";
    const vBln = "<?= $_GET['bln'] ?>";
    const vThn = "<?= $_GET['thn'] ?>";
    const vSub = "<?= $_GET['sub'] ?>";
    
    $(document).ready(function () {
      loadChart();
    })
      
    function tampilChart(grfnya, typenya, judulnya, xAxisnya, legendnya, datanya) {
      Highcharts.chart(grfnya, {
        chart: {
          type: typenya
        },
        title: {
          text: judulnya
        },
        xAxis: xAxisnya,
        yAxis: {
          title: {
            text: 'Jumlah (meter)'
          }
        },
        legend: {
          enabled: legendnya
        },
        tooltip: {
            pointFormat: '<b>{point.y:.f} M</b><br/>'

        },
        credits : {
          enabled : false
        },
        plotOptions: {
          column: {
            dataLabels: {
              enabled: true
            }  
          },
          line: {
            dataLabels: {
              enabled: true
            },
            enableMouseTracking: false
          },
        },
        series: datanya
      });
    }

    function loadChart() {
      $.ajax({
        url: FRM+ "?mode=salesvsprod&jns="+vJns+"&tgl="+vTgl+"&tglb="+vTglb+"&bln="+vBln+"&thn="+vThn+"&sub="+vSub, 
        success: function(result){
          var o = JSON.parse(result);
          tampilChart('kontener1', 'column', 'Sales Vs Hasil Produksi', {type: 'category',categories:o.kategori}, true, o.data_a);
          var tabelnya = '<style>td,th{padding-left:3px;padding-right:3px;}table.adaborder{border-collapse:collapse;width:100%;}table.adaborder th,table.adaborder td{border:1px solid black;}</style><table class="adaborder"><tr><th></th>';
          for(a of o.kategori){
            tabelnya += '<th>'+a+'</th>';  
          }
          tabelnya += '<th>TOTAL</th></tr>';
          tabelnya += '<tr><td style="font-weight:bold;">Saldo Awal</td>';
          var tot_saldo_awal = 0;
          for(a of o.saldo_awal) {
            tabelnya += '<td style="text-align:right;">'+a.toFixed(0).replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,")+'</td>';
            tot_saldo_awal += a;
          }
          tabelnya += '<td style="text-align:right;">'+tot_saldo_awal.toFixed(0).replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,")+'</td></tr>';
          for (a of o.data_a) {
            tabelnya += '<tr><td style="background-color:'+a.color+';color:white;font-weight:bold;">'+a.name+'</td>';
            var total = 0;
            for(b of a.data) {
              tabelnya += '<td style="text-align:right;">'+b.toFixed(0).replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,")+'</td>';
              total += b;
            }
            tabelnya += '<td style="text-align:right;">'+total.toFixed(0).replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,")+'</td></tr>';  
          }
          tabelnya += '<tr><td style="font-weight:bold;">Saldo Akhir</td>';
          var tot_saldo_akhir = 0;
          for(a of o.saldo_akhir) {
            tabelnya += '<td style="text-align:right;">'+a.toFixed(0).replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,")+'</td>';
            tot_saldo_akhir += a;
          }
          tabelnya += '<td style="text-align:right;">'+tot_saldo_akhir.toFixed(0).replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,")+'</td></tr>';
          tabelnya += '<tr><td style="font-weight:bold;">Kapasitas Gudang</td>';
          for(a of o.saldo_akhir) {
            tabelnya += '<td style="text-align:right;"></td>';
          }
          tabelnya += '<td style="text-align:right;">'+o.kapasitas_gudang.toFixed(0).replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,")+'</td></tr>';
          tabelnya += '<tr><td style="font-weight:bold;">Kapasitas Available</td>';
          var kapasitas_available = o.kapasitas_gudang - tot_saldo_akhir;
          for(a of o.saldo_akhir) {
            tabelnya += '<td style="text-align:right;"></td>';
          }
          tabelnya += '<td style="text-align:right;">'+kapasitas_available.toFixed(0).replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,")+'</td></tr>'; 
          tabelnya += '</table>';
          $('#kontener2').html(tabelnya);
        }
      });
    }
  </script>
</body>
</html>
