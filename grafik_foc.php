<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Grafik FOC</title>
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
        url: FRM+ "?mode=foc&jns="+vJns+"&bln="+vBln+"&thn="+vThn+"&sub="+vSub, 
        success: function(result){
          var o = JSON.parse(result);
          tampilChart('kontener1', 'column', 'FOC', {type: 'category',categories:o.kategori}, true, o.data_a);
          var tabelnya = '<style>td,th{padding-left:3px;padding-right:3px;}table.adaborder{border-collapse:collapse;width:100%;}table.adaborder th,table.adaborder td{border:1px solid black;}</style><table class="adaborder"><tr><th>UKURAN</th>';
          for(a of o.kategori){
            tabelnya += '<th>'+a+'</th>';  
          }
          tabelnya += '<th>TOTAL</th></tr>';
          var grand_tot = [];
          for (a of o.data_a) {
            var total = 0;
            var i = 0;
            tabelnya += '<tr><th>'+a.name+'</th>';
            for(b of a.data) {
              tabelnya += '<td style="text-align:right;">'+b.toFixed(0).replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,")+'</td>';
              total += b;
              if(isNaN(grand_tot[i])) {
                grand_tot[i] = 0
              }
              grand_tot[i] += b;
              i++;
            }
            tabelnya += '<td style="text-align:right;">'+total.toFixed(0).replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,")+'</td>';
            tabelnya += '</tr>';  
          } 
          tabelnya += '<tr><th>TOTAL</th>';
          var total = 0;
          for(a of grand_tot){
            tabelnya += '<td style="text-align:right;">'+a.toFixed(0).replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,")+'</td>';
            total += a;  
          }
          tabelnya += '<td style="text-align:right;">'+total.toFixed(0).replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,")+'</td>';
          tabelnya += '</tr></table>';
          $('#kontener2').html(tabelnya);
        }
      });
    }
  </script>
</body>
</html>
