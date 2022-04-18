<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Grafik</title>
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
  <script src="assets/libs/jquery/jquery.min.js"></script>
  <script src="assets/libs/highcharts/highcharts.js"></script>
  <script src="assets/libs/highcharts/modules/exporting.js"></script>
  <style>
    td,th {
      padding-left:3px;padding-right:3px;
    }
    table.adaborder {
      border-collapse:collapse;width:100%;
    }
    table.adaborder th,table.adaborder td {
      border:1px solid black;
    }
  </style>
</head>
<body>
  <div id="kontener1" style="min-width: 180px; margin: 0 auto"></div>
  <div id="kontener2"></div>

  <script type="text/javascript">
    const FRM = 'grafik.inc.php';
    const vJns = "<?= $_GET['jns'] ?>";
    const vTpe = "<?= $_GET['tpe'] ?>";
    const vTgl = "<?= $_GET['tgl'] ?>";
    const vTglb = "<?= $_GET['tglb'] ?>";
    const vThn = "<?= $_GET['thn'] ?>";
    const vMuat = "<?= $_GET['muat'] ?>";
    
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
        url: FRM+ "?mode=load&jns="+vJns+"&tpe="+vTpe+"&tgl="+vTgl+"&tglb="+vTglb+"&thn="+vThn+"&muat="+vMuat, 
        success: function(result){
          var o = JSON.parse(result);
          if((vJns == 'M' || vJns == 'B' || vJns == 'P') && vTpe == 'shift') {
            tampilChart('kontener1', 'column', 'Pemuatan', {type: 'category',categories:o.kategori}, true, o.data_a);
          } else {
            tampilChart('kontener1', 'column', 'Pemuatan', {type:'category',crosshair:true}, false, o.data_a);
          }
          $('#kontener2').html(o.tbl);
        }
      });
    }
  </script>
</body>
</html>
