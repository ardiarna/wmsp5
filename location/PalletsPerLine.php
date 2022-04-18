<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

SessionUtils::sessionStart();
$user = SessionUtils::getUser();
?>
<!DOCTYPE HTML>
<html lang="id">
<head>
  <title>Daftar Palet per Area/Baris</title>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
  <meta http-equiv="X-UA-Compatible" content="IE=edge"/>

  <link rel="stylesheet" type="text/css" href="../assets/libs/dhtmlx/dhtmlx.css"/>
  <link rel="stylesheet" type="text/css" href="../assets/fonts/font_roboto/roboto.css"/>
  <link rel="stylesheet" type="text/css" href="../assets/fonts/font_awesome/css/font-awesome.min.css"/>
  <style>
    html, body {
      width: 100%;
      height: 100%;
      overflow: hidden;
      margin: 0px;
      background-color: #EBEBEB;
    }

    div.dhxform_item_label_left.button_width div.dhxform_btn_txt {
      padding-left: 0px;
      padding-right: 0px;
      margin: 0px 0px 0px 0px;
    }

  </style>

  <script src="../assets/libs/dhtmlx/dhtmlx.js"></script>
  <script src="../assets/libs/axios/axios.min.js"></script>
  <script src="../assets/libs/moment/moment-with-locales.min.js"></script>
  <script src="../assets/libs/pdfmake/pdfmake.min.js"></script>
  <script src="../assets/libs/pdfmake/vfs_fonts.js"></script>
  <script src="../assets/libs/js-cookie/js.cookie.min.js"></script>

  <script src="../assets/js/date-utils.js"></script>
  <script src="../assets/js/WMSApi-20190711-01.js"></script>
  <script src="../assets/js/grid-utils-20190704-01.js"></script>
  <script src="../assets/js/grid-custom-types-20190704-01.js"></script>
  <script>
    dhx.ajax.cache = true; // fix barcode not showing.
    const report = (function (moment, gridUtils, Cookies, WMSApi) {
      WMSApi.setBaseUrl('../api');

      const CELL_SELECT_LINE_FORM = 'a';
      const CELL_PALLETS_PER_LINE_GRID = 'b';
      const USERID = '<?= $user->gua_kode ?>';

      const STYLES = gridUtils.styles;
      const FILTERS = gridUtils.headerFilters;

      let grid_palletsPerLine, layout_root;
      let form_selectLine;

      let windows;
      function doOnLoad() {
        layout_root = new dhtmlXLayoutObject({
          parent: document.body,
          pattern: '2E',
          cells: [
            {id: CELL_SELECT_LINE_FORM, text: 'Pilih Area dan Baris', height: 90},
            {id: CELL_PALLETS_PER_LINE_GRID, text: 'Daftar Palet'}
          ]
        });

        windows = new dhtmlXWindows();
        // setup selectLine part
        form_selectLine = layout_root.cells(CELL_SELECT_LINE_FORM).attachForm([
          {type: "settings", position: "label-left", labelWidth: 70, inputWidth: 160},
          {
            type: "block", offsetTop: 0, width: 400, blockOffset: 0, list: [
              {
                type: 'template',
                name: 'show_areas',
                label: 'Area',
                format: () => '<a href="javascript:void(0);" onclick="report.showAreaWindow();"><i class="fa fa-search fa-2x"></i></a>',
                inputWidth: 25
              },
              {type: 'newcolumn'},
              {type: 'hidden', name: 'subplant', value: ''},
              {type: 'hidden', name: 'area_code', value: ''},
              {type: 'input', name: 'area_label', inputWidth: 200, readonly: true},
            ]
          },
          {type: 'newcolumn'},
          {
            type: 'combo',
            name: 'line',
            label: 'Baris',
            inputWidth: 80,
            required: true,
            readonly: true
          },
          {type: 'newcolumn'},
          {type: 'button', offsetLeft: 30, name: 'search', value: 'Lihat Palet', disabled: true}
        ]);
        form_selectLine.attachEvent('onButtonClick', function (id) {
          if (id === 'search') {
            const areaCode = form_selectLine.getItemValue('area_code');
            if (!areaCode) {
              dhtmlx.alert({
                title: 'Area Belum Dipilih!',
                type: "alert-warning",
                text: 'Silahkan pilih area terlebih dahulu.'
              });
              return false;
            } else {
              const subplant = form_selectLine.getItemValue('subplant');
              const line = form_selectLine.getItemValue('line');
              fetchPallets(subplant, areaCode, line);
            }
          } else if (id === 'print') {
            print()
          }
        });

        function openPDFDocument(blob, title) {
          const pdfWin = windows.createWindow("w2", 0, 0, 800, 450);
          pdfWin.centerOnScreen();
          pdfWin.setText(title);
          pdfWin.button("park").hide();
          pdfWin.setModal(true);

          const fileName = title + '.pdf';
          const file = new File([blob], fileName, {type: 'application/pdf', lastModified: Date.now()});
          let fileURL = URL.createObjectURL(file);

          pdfWin.attachURL(fileURL);
          pdfWin.attachEvent('onClose', () => {
            if (fileURL) {
              URL.revokeObjectURL(fileURL);
              blob = null;
              fileURL = null;
            }
            return true
          });
          return pdfWin
        }

        const toolbar_palletsPerLine = layout_root.cells(CELL_PALLETS_PER_LINE_GRID).attachToolbar({
          iconset: 'awesome',
          items: [
            {type: 'button', id: 'refresh', text: 'Segarkan', img: 'fa fa-refresh'},
            {type: 'separator'},
            {type: 'button', id: 'clear_filters', text: 'Bersihkan Penyaring Data', img: 'fa fa-close'},
            {type: 'separator'},
            {type: 'button', id: 'print', text: 'Cetak', img: 'fa fa-print'},
            {type: 'button', id: 'export_csv', text: 'Ke CSV', img: 'fa fa-file-excel-o'},
            {type: 'button', id: 'export_pdf', text: 'Ke PDF', img: 'fa fa-file-pdf-o'},
            {type: 'spacer'},
            {type: 'text', id: 'timestamp', text: ''}
          ]
        });
        toolbar_palletsPerLine.attachEvent('onClick', itemId => {
          const areaLabel = form_selectLine.getItemValue('area_label');
          const areaCode = form_selectLine.getItemValue('area_code');
          const subplant = form_selectLine.getItemValue('subplant');
          const line = form_selectLine.getItemValue('line');
          let title = `Daftar Palet di ${areaLabel}`;
          if (line !== 'all') {
            title += ` - Baris ${line.toString().padStart(<?= PlantIdHelper::usesLocationCell() ? 2 : 3 ?>, '0')}`;
          }

          const pdfWidths = '30,60,120,80,45,35,*,70,30,40,30,60,40,120';
          const cell = layout_root.cells(CELL_PALLETS_PER_LINE_GRID);
          switch (itemId) {
            case 'refresh':
              fetchPallets(subplant, areaCode, line);
              break;
            case 'clear_filters':
              gridUtils.clearAllGridFilters(grid_palletsPerLine);
              break;
            case 'print':
              cell.progressOn();
              gridUtils.generateFilteredPdf(grid_palletsPerLine, title, USERID, pdfWidths)
                .getBlob(blob => {
                  cell.progressOff();
                  openPDFDocument(blob, title);
                }, { autoPrint: true });
              break;
            case 'export_csv':
              const csvTitle = `${moment().format('YYYY-MM-DD')} - ${title}`;
              gridUtils.downloadFilteredCSV(grid_palletsPerLine, csvTitle);
              break;
            case 'export_pdf':
              const pdfTitle = `${moment().format('YYYY-MM-DD')} - ${title}`;
              gridUtils.generateFilteredPdf(grid_palletsPerLine, title, USERID, pdfWidths)
                .getBlob(blob => {
                  cell.progressOff();
                  openPDFDocument(blob, pdfTitle);
                });
              break;
          }
        });

        grid_palletsPerLine = layout_root.cells(CELL_PALLETS_PER_LINE_GRID).attachGrid();
        grid_palletsPerLine.setHeader("BRS.,KD. LOK.,NO. PLT.,KD. MOTIF,DIM.,QLTY.,MOTIF,TGL. PROD.,LINE,SHIFT,SIZE,SHADING,QTY.,SEJAK", null,
          [STYLES.TEXT_RIGHT_ALIGN, '', '', '', '', '', '', STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_RIGHT_ALIGN, '', '', STYLES.TEXT_RIGHT_ALIGN, '']);
        grid_palletsPerLine.setColumnIds('location_line_no,location_id,pallet_no,motif_id,motif_dimension,quality,motif_name,created_at,line,creator_shift,size,shading,current_quantity,location_since');
        grid_palletsPerLine.setColTypes('ron,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,ro_date,ron,ron,rotxt,rotxt,ron,ro_ts');
        grid_palletsPerLine.setInitWidths("45,70,120,80,50,50,*,80,50,50,40,65,50,120");
        grid_palletsPerLine.setColAlign("right,left,left,left,left,left,left,left,right,right,left,left,right,left");
        grid_palletsPerLine.setColSorting("int,str,str,str,str,str,str,str,int,int,str,str,int,str");
        grid_palletsPerLine.attachHeader([FILTERS.NUMERIC, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.NUMERIC, FILTERS.TEXT]);
        grid_palletsPerLine.attachFooter(['', '', 'Total', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', gridUtils.reducers.STATISTICS_COUNT, gridUtils.reducers.STATISTICS_TOTAL, '']
          , ['', '', STYLES.TEXT_RIGHT_ALIGN + STYLES.TEXT_BOLD, '', '', '', '', '', '', '', '', STYLES.TEXT_RIGHT_ALIGN + STYLES.TEXT_BOLD, STYLES.TEXT_RIGHT_ALIGN + STYLES.TEXT_BOLD, '']);

        <?php if(!PlantIdHelper::usesLocationCell()): ?>
        grid_palletsPerLine.setColumnHidden(grid_palletsPerLine.getColIndexById('location_id'), true);
        <?php endif ?>
        grid_palletsPerLine.setColumnHidden(grid_palletsPerLine.getColIndexById('motif_id'), true);
        grid_palletsPerLine.setColumnHidden(grid_palletsPerLine.getColIndexById('location_since'), true);
        grid_palletsPerLine.setNumberFormat("0,000", grid_palletsPerLine.getColIndexById('current_quantity'), ",", ".");
        grid_palletsPerLine.attachEvent("onXLS", function () {
          layout_root.cells(CELL_PALLETS_PER_LINE_GRID).progressOn();
        });
        grid_palletsPerLine.attachEvent("onXLE", function () {
          layout_root.cells(CELL_PALLETS_PER_LINE_GRID).progressOff()
        });
        grid_palletsPerLine.enableSmartRendering(true, 100);
        grid_palletsPerLine.init();
      }

      function fetchPallets(subplant, areaCode, line) {
        layout_root.cells(CELL_PALLETS_PER_LINE_GRID).progressOn();
        return WMSApi.location.fetchPalletsByLine(subplant, areaCode, line)
          .then(pallets => ({
            data: pallets.map(pallet => ({
              id: pallet.pallet_no,
              location_line_no: pallet.location_line_no,
              location_id: pallet.location_id,
              location_since: pallet.location_since,
              pallet_no: pallet.pallet_no,
              motif_id: pallet.motif_id,
              motif_dimension: pallet.motif_dimension,
              motif_name: pallet.motif_name,
              created_at: pallet.created_at,
              line: pallet.line,
              quality: pallet.quality,
              creator_shift: pallet.creator_shift,
              size: pallet.size,
              shading: pallet.shading,
              current_quantity: pallet.current_quantity
            }))
          }))
          .then(data => {
            gridUtils.clearAllGridFilters(grid_palletsPerLine);
            grid_palletsPerLine.clearAll();
            grid_palletsPerLine.setColumnHidden(grid_palletsPerLine.getColIndexById('location_line_no'), line !== 'all');
            grid_palletsPerLine.parse(data, 'js');

            layout_root.cells(CELL_PALLETS_PER_LINE_GRID).progressOff();

            // show no pallet notification
            if (data.data.length === 0) {
              dhtmlx.message(`Tidak ada palet pada lokasi ${subplant}-${areaCode}${line === 'all' ? '' : `-${line.padStart(<?= PlantIdHelper::usesLocationCell() ? 2 : 3 ?>, '0')}`}!`)
            }
          })
          .catch(error => {
            layout_root.cells(CELL_PALLETS_PER_LINE_GRID).progressOff();
            console.error(error);
            dhtmlx.alert({
              type: "alert-warning",
              text: error instanceof Object ? error.message : error,
              title: 'Error'
            })
          })
      }

      function showAreaWindow() {
        // initialize window
        const window_areas = windows.createWindow("w1", 0, 0, 600, 300);
        window_areas.centerOnScreen();
        window_areas.setText('Daftar Area');
        window_areas.button("park").hide();
        window_areas.setModal(true);
        window_areas.button("minmax1").hide();

        // initialize toolbar
        const toolbar_areas = window_areas.attachToolbar({
          iconset: 'awesome',
          items: [
            {type: 'button', id: 'refresh', text: 'Segarkan', img: 'fa fa-refresh'}
          ]
        });
        toolbar_areas.attachEvent('onClick', itemId => {
          if (itemId === 'refresh') {
            fetchAllLocations();
          }
        });

        // initialize grid
        const grid_area = window_areas.attachGrid();

        grid_area.setHeader('SUBP.,KD.,AREA,TTL.BRS,AKTIF', null, ['', '', '', STYLES.TEXT_RIGHT_ALIGN, '']);
        grid_area.setColTypes('ro,ro,ro,ron,ro_bool');
        grid_area.setColumnIds('subplant,area_code,area_name,line_count,is_active');
        grid_area.setInitWidths('60,60,*,*,60');
        grid_area.attachHeader([FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.NUMERIC, FILTERS.SELECT]);
        grid_area.setColAlign('left,left,left,right,left');
        grid_area.setColSorting('str,str,str,int,str');

        grid_area.attachEvent("onXLS", function () {
          window_areas.progressOn();
        });
        grid_area.attachEvent("onXLE", function () {
          window_areas.progressOff()
        });
        grid_area.init();

        grid_area.attachEvent("onRowDblClicked", function (rowId) {
          const subplant = grid_area.cells(rowId, grid_area.getColIndexById('subplant')).getValue();
          const areaCode = grid_area.cells(rowId, grid_area.getColIndexById('area_code')).getValue();
          const areaName = grid_area.cells(rowId, grid_area.getColIndexById('area_name')).getValue();
          const lineCount = grid_area.cells(rowId, grid_area.getColIndexById('line_count')).getValue();

          // populate with line count
          const lineOptions = [];
          if (lineCount > 1) {
            lineOptions.push({text: 'Semua', value: 'all', selected: true});
            for (let i = 1; i <= lineCount; i++) {
              // noinspection JSCheckFunctionSignatures
              lineOptions.push({text: `${i}`, value: `${i}`, selected: false});
            }
          } else {
            lineOptions.push({text: '1', value: '1', selected: true});
          }

          // set the form
          form_selectLine.setItemValue('subplant', subplant);
          form_selectLine.setItemValue('area_code', areaCode);
          form_selectLine.reloadOptions('line', lineOptions);
          form_selectLine.enableItem('search');

          const areaLabel = `${subplant} - ${areaCode} - ${areaName}`;
          form_selectLine.setItemValue('area_label', areaLabel);

          window_areas.close();

          // clear also the existing pallet list
          grid_palletsPerLine.clearAll();
        });
        fetchAllLocations();

        function fetchAllLocations() {
          window_areas.progressOn();
          return WMSApi.location.fetchAllLocations()
            .then(locations => {
              const data = locations.map(location => ({
                id: `${location.subplant}_${location.area_code}`,
                subplant: location.subplant,
                area_code: location.area_code,
                area_name: location.area_name,
                line_count: location.line_count,
                is_active: location.is_active
              }));
              return {data}
            })
            .then(data => {
              grid_area.clearAll();
              grid_area.parse(data, 'js');
              gridUtils.clearAllGridFilters(grid_area);
            })
            .catch(error => {
              window_areas.progressOff();
              console.error(error);
              dhtmlx.alert({
                type: "alert-warning",
                text: error instanceof Object ? error.message : error,
                title: 'Error'
              })
            })
        }
      }

      return {doOnLoad, showAreaWindow}
    })(moment, gridUtils, Cookies, WMSApi);
  </script>
</head>
<body onload="report.doOnLoad()">

</body>
</html>
