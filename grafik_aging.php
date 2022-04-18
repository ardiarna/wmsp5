<?
$arr_ukuran = array('05' => '20 X 20', '06' => '30 X 30', '07' => '40 X 40', '08' => '20 X 25', '09' => '25 X 40', '10' => '25 X 25', '11' => '50 X 50', '12' => '25 X 50', '13' => '60 X 60');
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Grafik Aging</title>
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
  <script src="assets/libs/jquery/jquery.min.js"></script>
  <script src="assets/libs/highcharts/highcharts.js"></script>
  <script src="assets/libs/highcharts/modules/exporting.js"></script>
</head>
<body style="background-color:#2a2a2b;color:white;">
  <div id="kontener1" style="min-width: 180px; margin: 0 auto"></div>
  <div id="kontener2"></div>

  <script type="text/javascript">
    const FRM = 'grafik.inc.php';
    const vJns = "<?= $_GET['jns'] ?>";
    const vTgl = "<?= $_GET['tgl'] ?>";
    const vTglb = "<?= $_GET['tglb'] ?>";
    const vUk = "<?= $_GET['uk'] ?>";
    
    $(document).ready(function () {
      loadChart();
    })
      
    function tampilChart(grfnya, typenya, judulnya, xAxisnya, legendnya, datanya) {
      Highcharts.theme = {
        colors: ["#2b908f", "#90ee7e", "#f45b5b", "#7798BF", "#aaeeee", "#ff0066", "#eeaaee", "#55BF3B", "#DF5353", "#7798BF", "#aaeeee"],
        chart: {
          backgroundColor: {
            linearGradient: { x1: 0, y1: 0, x2: 1, y2: 1 },
            stops: [
              [0, "#2a2a2b"],
              [1, "#3e3e40"],
            ],
          },
          style: {
            fontFamily: "'Unica One', sans-serif",
          },
          plotBorderColor: "#606063",
        },
        title: {
          style: {
            color: "#E0E0E3",
            textTransform: "uppercase",
            fontSize: "20px",
          },
        },
        subtitle: {
          style: {
            color: "#E0E0E3",
            textTransform: "uppercase",
          },
        },
        xAxis: {
          gridLineColor: "#707073",
          labels: {
            style: {
              color: "#E0E0E3",
            },
          },
          lineColor: "#707073",
          minorGridLineColor: "#505053",
          tickColor: "#707073",
          title: {
            style: {
              color: "#A0A0A3",
            },
          },
        },
        yAxis: {
          gridLineColor: "#707073",
          labels: {
            style: {
              color: "#E0E0E3",
            },
          },
          lineColor: "#707073",
          minorGridLineColor: "#505053",
          tickColor: "#707073",
          tickWidth: 1,
          title: {
            style: {
              color: "#A0A0A3",
            },
          },
        },
        tooltip: {
          backgroundColor: "rgba(0, 0, 0, 0.85)",
          style: {
            color: "#F0F0F0",
          },
        },
        plotOptions: {
          series: {
            dataLabels: {
              color: "#B0B0B3",
            },
            marker: {
              lineColor: "#333",
            },
          },
          boxplot: {
            fillColor: "#505053",
          },
          candlestick: {
            lineColor: "white",
          },
          errorbar: {
            color: "white",
          },
        },
        legend: {
          itemStyle: {
            color: "#E0E0E3",
          },
          itemHoverStyle: {
            color: "#FFF",
          },
          itemHiddenStyle: {
            color: "#606063",
          },
        },
        credits: {
          style: {
            color: "#666",
          },
        },
        labels: {
          style: {
            color: "#707073",
          },
        },

        drilldown: {
          activeAxisLabelStyle: {
            color: "#F0F0F3",
          },
          activeDataLabelStyle: {
            color: "#F0F0F3",
          },
        },

        navigation: {
          buttonOptions: {
            symbolStroke: "#DDDDDD",
            theme: {
              fill: "#505053",
            },
          },
        },

        // scroll charts
        rangeSelector: {
          buttonTheme: {
            fill: "#505053",
            stroke: "#000000",
            style: {
              color: "#CCC",
            },
            states: {
              hover: {
                fill: "#707073",
                stroke: "#000000",
                style: {
                  color: "white",
                },
              },
              select: {
                fill: "#000003",
                stroke: "#000000",
                style: {
                  color: "white",
                },
              },
            },
          },
          inputBoxBorderColor: "#505053",
          inputStyle: {
            backgroundColor: "#333",
            color: "silver",
          },
          labelStyle: {
            color: "silver",
          },
        },

        navigator: {
          handles: {
            backgroundColor: "#666",
            borderColor: "#AAA",
          },
          outlineColor: "#CCC",
          maskFill: "rgba(255,255,255,0.1)",
          series: {
            color: "#7798BF",
            lineColor: "#A6C7ED",
          },
          xAxis: {
            gridLineColor: "#505053",
          },
        },

        scrollbar: {
          barBackgroundColor: "#808083",
          barBorderColor: "#808083",
          buttonArrowColor: "#CCC",
          buttonBackgroundColor: "#606063",
          buttonBorderColor: "#606063",
          rifleColor: "#FFF",
          trackBackgroundColor: "#404043",
          trackBorderColor: "#404043",
        },

        // special colors for some of the
        legendBackgroundColor: "rgba(0, 0, 0, 0.5)",
        background2: "#505053",
        dataLabelsColor: "#B0B0B3",
        textColor: "#C0C0C0",
        contrastTextColor: "#F0F0F3",
        maskColor: "rgba(255,255,255,0.3)",
      };
      // Apply the theme
      Highcharts.setOptions(Highcharts.theme);

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
            text: 'Jumlah (M2)'
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
        url: FRM+ "?mode=aging&jns="+vJns+"&tgl="+vTgl+"&tglb="+vTglb+"&uk="+vUk, 
        success: function(result){
          var o = JSON.parse(result);
          tampilChart('kontener1', 'column', o.judul, {type: 'category',categories:o.kategori}, true, o.data_a);
          var tabelnya = '<style>td,th{padding-left:3px;padding-right:3px;}table.adaborder{border-collapse:collapse;width:100%;}table.adaborder th,table.adaborder td{border:1px solid white;}</style><table class="adaborder"><tr><th></th>';
          for(a of o.kategori){
            var tanggal = new Date(a);
            var hari = tanggal.getDay();
            if (hari == 0) {
              tabelnya += '<th style="background-color:deeppink;">'+a+'</th>';
            } else {
              tabelnya += '<th>'+a+'</th>';  
            }      
          }
          tabelnya += '<th>TOTAL</th></tr>';
          var grand_tot = [];
          for (a of o.data_a) {
            var total = 0;
            var i = 0;
            tabelnya += '<tr><th style="background-color:'+a.color+';color:white;font-weight:bold;">'+a.name+'</th>';
            for(b of a.data) {
              tabelnya += '<td style="text-align:right;">'+b.toFixed(0).replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,")+'</td>';
              total += b;
              if(isNaN(grand_tot[i])) {
                grand_tot[i] = 0;
              }
              grand_tot[i] += b;
              i++;
            }
            tabelnya += '<td style="text-align:right;">'+total.toFixed(0).replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,")+'</td></tr>';  
          }
          tabelnya += '<tr><th>TOTAL</th>';
          var total = 0;
          for(a of grand_tot){
            tabelnya += '<th style="text-align:right;">'+a.toFixed(0).replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,")+'</th>';
            total += a;  
          }
          tabelnya += '<th style="text-align:right;">'+total.toFixed(0).replace(/(\d)(?=(\d\d\d)+(?!\d))/g, "$1,")+'</th>'; 
          tabelnya += '</tr></table>';
          $('#kontener2').html(tabelnya);
        }
      });
    }
  </script>
</body>
</html>
