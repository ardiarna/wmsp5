<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Security\RoleAcl;
use Utils\Env;

SessionUtils::sessionStart();

$user = SessionUtils::getUser();

?>
<!DOCTYPE HTML>
<html lang="id">
<head>

  <title>Laporan Aging</title>
  <link rel="stylesheet" type="text/css" href="../assets/libs/dhtmlx/dhtmlx.css"/>
  <link rel="stylesheet" type="text/css" href="../assets/fonts/font_awesome/css/font-awesome.min.css"/>
  <link rel="stylesheet" type="text/css" href="../assets/fonts/font_roboto/roboto.css"/>

  <script src="../assets/libs/dhtmlx/dhtmlx.js"></script>
  <script src="../assets/libs/axios/axios.min.js"></script>
  <script src="../assets/libs/pdfmake/pdfmake.min.js"></script>
  <script src="../assets/libs/pdfmake/vfs_fonts.js"></script>
  <script src="../assets/libs/js-cookie/js.cookie.min.js"></script>
  <script src="../assets/libs/moment/moment-with-locales.min.js"></script>

  <script src="../assets/js/date-utils.js"></script>
  <script src="../assets/js/WMSApi-20211112.js"></script>
  <script src="../assets/js/grid-custom-types.js"></script>
  <script src="../assets/js/grid-utils.js"></script>
  <script src="../assets/js/error-handler.js"></script>
  <script src="../assets/js/views/items-for-blocking.js"></script>
  <script src="../assets/js/views/dhtmlx.prompt.js"></script>
  <style>
    html, body {
      width: 100%;
      height: 100%;
      overflow: hidden;
      margin: 0;
      background-color: #EBEBEB;
    }

    div.dhxform_item_label_left.button_width div.dhxform_btn_txt {
      padding-left: 0;
      padding-right: 0;
      margin: 0 0 0 0;
    }

    .btn-success > .dhxform_btn {
      background-color: #4cae4c !important;
    }
    .btn-danger > .dhxform_btn {
      background-color: #761c19 !important;;
    }
  </style>
  <script>

    const USERID = '<?= $user->gua_kode ?>';
    const SUBPLANTS_OPTIONS = ['<?= implode('\',\'', $user->gua_subplant_handover) ?>'];
    const STATUS_OPTIONS = {'O':'Dalam Proses','C':'Dibatalkan','S':'Selesai'};

    function eXcell_orderStatus(cell) { // the eXcell name is defined here
      this.base = eXcell_ro;
      this.base(cell);
      this.setValue = function (val) {
        // actual data processing may be placed here, for now we just set value as it is
        this.setCValue(`<span data-val="${val}">${STATUS_OPTIONS[val]}</span>`);
      };

      this.getValue = function () {
        return this.cell.firstChild.dataset.val
      }
    }
    // noinspection JSPotentiallyInvalidConstructorUsage
    eXcell_orderStatus.prototype = new eXcell_ro;// nests all other methods from the base class

    WMSApi.setBaseUrl('../api');

    moment.locale('id');
    moment.defaultFormat = 'D MMM YYYY, HH:mm:ss';

    const TEXT_RIGHT_ALIGN = gridUtils.styles.TEXT_RIGHT_ALIGN;
    const TEXT_BOLD = gridUtils.styles.TEXT_BOLD;

    const HEADER_TEXT_FILTER = gridUtils.headerFilters.TEXT;
    const HEADER_NUMERIC_FILTER = gridUtils.headerFilters.NUMERIC;
    const COLUMN_SPAN = gridUtils.spans.COLUMN;

    const CELL_REQUEST_FILTER = 'a';
    const CELL_REQUEST_LIST = 'b';

    let windows;
    let rootLayout;

    function doOnLoad() {
      rootLayout = new dhtmlXLayoutObject({
        parent: document.body,
        pattern: '2E',
        cells: [
          {id: CELL_REQUEST_FILTER, header: true, text: 'Laporan Aging', height: 90},
          {id: CELL_REQUEST_LIST, header: false}
        ]
      });
      windows = new dhtmlXWindows();

      setupRequestFilters(rootLayout.cells(CELL_REQUEST_FILTER));

      rootLayout.cells(CELL_REQUEST_LIST).detachToolbar();
      setupRequestGrid(rootLayout.cells(CELL_REQUEST_LIST));
      
      
    }

    function setupRequestFilters(cell) {
      const ALL = {text: 'Semua', value: 'all', selected: true};
      // transform options
      const subplantOptions = [ALL].concat(SUBPLANTS_OPTIONS.map(subplant => ({text: subplant, value: subplant})));

      var arrTahun = [];

      for (var i = 2000; i <= 2030; i++) {
        if(i == moment().format('YYYY')) {
          arrTahun.push({text: i, value: i, selected: true});
        } else {
          arrTahun.push({text: i, value: i});  
        }
      }
    
      const filterFormConfig = [
        {type: "settings", position: "label-left", inputWidth: 100},
        {
          type: 'combo',
          name: 'locationsubplant',
          label: 'Location Subplant',
          required: true,
          labelWidth: 110,
          offsetLeft: 10,
          options: [{text: "05", value: "05"}]
        },
        {type: 'newcolumn'},
        {
          type: 'combo',
          name: 'dimension',
          label: 'Motif Dimension',
          required: true,
          labelWidth: 100,
          offsetLeft: 30,
          options: [{text: "", value: ""}, {text: "20 X 20", value: "20 X 20"}, {text: "30 X 30", value: "30 X 30"}, {text: "40 X 40", value: "40 X 40"}, {text: "20 X 25", value: "20 X 25"}, {text: "25 X 40", value: "25 X 40"}, {text: "25 X 25", value: "25 X 25"}, {text: "50 X 50", value: "50 X 50"}, {text: "25 X 50", value: "25 X 50"}, {text: "60 X 60", value: "60 X 60"}]
        },
        {type: 'newcolumn'},
        {
          type: 'combo',
          name: 'isrimpil',
          label: 'Is Rimpil',
          required: true,
          offsetLeft: 30,
          labelWidth: 70,
          options: [{text: "", value: ""}, {text: "FALSE", value: "false"}, {text: "TRUE", value: "true"}]
        },
        {type: 'newcolumn'},
        {type: 'button', offsetLeft: 20, name: 'getdata', value: 'Dapatkan Data!'}
      ];
      const filterForm = cell.attachForm(filterFormConfig);

      filterForm.attachEvent('onButtonClick', id => {
        if (id === 'getdata') {
          const locationsubplant = filterForm.getItemValue('locationsubplant');
          const dimension = filterForm.getItemValue('dimension');
          const isrimpil = filterForm.getItemValue('isrimpil');
          fetchAvailableRequests(locationsubplant, dimension, isrimpil);
        }
      });

      return cell;
    }

    function fetchAvailableRequests(locationsubplant, dimension, isrimpil) {
      if(locationsubplant == '') {
        dhtmlx.message('Location Subplant belum dipilih');
        return;
      }

      if(dimension == '') {
        dhtmlx.message('Motif Dimension belum dipilih');
        return;
      }

      if(isrimpil == '') {
        dhtmlx.message('Is Rimpil belum dipilih');
        return;
      }
      
      const cell = rootLayout.cells(CELL_REQUEST_LIST);
      cell.progressOn();
      return WMSApi.location.getLapAging(locationsubplant, dimension, isrimpil)
        .then(requests => requests.map(request => Object.assign(request, { id: request.idnya })))
        .then(requests => {
          cell.progressOff();
          const toolbar = cell.getAttachedToolbar();
          const grid = cell.getAttachedObject();
          grid.clearAll();

          grid.parse(requests, 'js');
          toolbar.setItemText('timestamp', moment().format());

          if (requests.length === 0) {
            dhtmlx.message('Tidak ada data aging berdasarkan permintaan yang dipilih!');
          }
        })
        .catch(error => {
          cell.progressOff();
          handleApiError(error);
        })
    }

    function setupRequestGrid(cell) {
      const toolbarConfig = {
        iconset: 'awesome',
        items: [
          {
            type: 'button',
            id: 'clear_filters',
            text: 'Bersihkan Penyaring Data',
            img: 'fa fa-trash',
            imgdis: 'fa fa-trash'
          },
          {type: 'separator'},
          {type: 'button', id: 'print', text: 'Cetak', img: 'fa fa-print'},
          {type: 'button', id: 'export_csv', text: 'Ke CSV', img: 'fa fa-file-excel-o'},
          {type: 'spacer'},
          {type: 'text', id: 'timestamp', text: ''}
        ]
      };
      const toolbar = cell.attachToolbar(toolbarConfig);

      toolbar.attachEvent('onClick', itemId => {
        const title = `Laporan Aging Plant <?= PlantIdHelper::getCurrentPlant() ?>`;
        const exportTitle = `${title}`;
        if(itemId === 'clear_filters') {
          gridUtils.clearAllGridFilters(requestGrid);
        } else if(itemId === 'print') {
          const pdfColWidths = '45,150,90,90,90,90,90,90,90,90,90,90,90,90,90';
          cell.progressOn();
          gridUtils.generateFilteredPdf(requestGrid, exportTitle, USERID, pdfColWidths)
            .getBlob(blob => {
              cell.progressOff();
              gridUtils.openPDFWindow(exportTitle, exportTitle, blob);
            }, {autoPrint: true});
        } else if(itemId === 'export_csv') {
          cell.progressOn();
          gridUtils.downloadFilteredCSV(requestGrid, exportTitle);
          cell.progressOff();
        } else if(itemId === 'export_pdf') {
          const pdfColWidths = '45,150,90,90,90,90,90,90,90,90,90,90,90,90,90';
          cell.progressOn();
          const filename = exportTitle + '.pdf';
          gridUtils.generateFilteredPdf(requestGrid, title, USERID, pdfColWidths)
            .download(filename);
          cell.progressOff();
        }
      });

      // initialize grid
      const COLSPAN = gridUtils.spans.COLUMN + ',';
      const ROWSPAN = gridUtils.spans.ROW;
      const TEXT_CENTER = gridUtils.styles.TEXT_CENTER_ALIGN;
      const FILTERS = gridUtils.headerFilters;
      const requestGrid = cell.attachGrid();
      requestGrid.setHeader('NO,MOTIF NAME,SIZE,SHADE,QUALITY,A. 1-30 DAYS,B.31-60 DAYS,C.61-90 DAYS,D.91-120 DAYS,E.121-150 DAYS,F.151-180 DAYS,G.181-210 DAYS,H.211-240 DAYS,I. >240 DAYS,TOTAL');
      requestGrid.setColumnIds('no,motif_name,size,shading,quality,a,b,c,d,e,f,g,h,i,total');
      requestGrid.setColTypes('cntr,rotxt,rotxt,ron,rotxt,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron');
      requestGrid.setInitWidths('45,150,90,90,90,90,90,90,90,90,90,90,90,90,120');
      requestGrid.setColAlign('center,left,center,center,left,right,right,right,right,right,right,right,right,right,right');
      requestGrid.setColSorting('na,str,str,int,str,int,int,int,int,int,int,int,int,int,int');
      requestGrid.attachHeader([
        '&nbsp;', FILTERS.TEXT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT]);
      requestGrid.attachFooter(['Grand Total', '#cspan', '#cspan', '#cspan', '#cspan', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total'],
          [TEXT_RIGHT_ALIGN + TEXT_BOLD, '', '', '', '', TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD]);
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('a'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('b'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('c'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('d'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('e'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('f'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('g'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('h'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('i'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('total'), ",", ".");
      requestGrid.init();
      requestGrid.attachEvent('onRowSelect', rowId => {
        const cellfil = rootLayout.cells(CELL_REQUEST_FILTER);
        const filterForm = cellfil.getAttachedObject();
        const locationsubplant = filterForm.getItemValue('locationsubplant');
        const dimension = filterForm.getItemValue('dimension');
        const isrimpil = filterForm.getItemValue('isrimpil');
        const selectedOrderId = requestGrid.getSelectedRowId();
        const motif = requestGrid.cells(selectedOrderId, requestGrid.getColIndexById('motif_name')).getValue();
        const size = requestGrid.cells(selectedOrderId, requestGrid.getColIndexById('size')).getValue();
        const shading = requestGrid.cells(selectedOrderId, requestGrid.getColIndexById('shading')).getValue();
        const quality = requestGrid.cells(selectedOrderId, requestGrid.getColIndexById('quality')).getValue();
        openRequestDetails(windows, {
          locationsubplant : locationsubplant,
          dimension : dimension,
          isrimpil : isrimpil,
          motif : motif,
          size : size,
          shading : shading,
          quality : quality
        });
      });
      return cell;
    }

    function openRequestDetails(windows, options) {
      const win = windows.createWindow('dwg_request_details', 0, 0, 700, 500);
      const winTitle = 'Detail Laporan Aging';

      win.centerOnScreen();
      win.setText(winTitle);
      win.button("park").hide();
      win.setModal(true);
      win.maximize();

      const CELL_DETAILS_ITEMS = 'a';
      const CELL_DETAILS_ACTIONS = 'b';

      const detailsLayout = win.attachLayout({
        pattern: '2E',
        cells: [
          {id: CELL_DETAILS_ITEMS, fix_size: true, header: false},
          {id: CELL_DETAILS_ACTIONS, height: 60, fix_size: true, header: false}
        ]
      });

      // setup grid + toolbar
      const toolbarConfig = {
        iconset: 'awesome',
        items: [
          { type: 'button', id: 'export_csv', text: 'Ke CSV', img: 'fa fa-file-excel-o', imgdis: 'fa fa-file-excel-o' }
        ]
      };
      const toolbar = detailsLayout.cells(CELL_DETAILS_ITEMS).attachToolbar(toolbarConfig);

      toolbar.attachEvent('onClick', id => {
        switch (id) {
          case 'export_csv': {
            win.progressOn();
            const exportTitle = winTitle;
            gridUtils.downloadFilteredCSV(grid, exportTitle);
            win.progressOff();
            break;
          }
        }
      });
      const FILTERS = gridUtils.headerFilters;
      const grid = detailsLayout.cells(CELL_DETAILS_ITEMS).attachGrid();
      grid.setHeader('NO,LOCATION SUBPLANT,LOCATION AREA NAME,LOCATION AREA NO,LOCATION LINE NO,LOCATION ID,PALLET NO,PRODUCTION SUBPLANT,MOTIF ID,MOTIF DIMENSION,MOTIF NAME,QUALITY,SIZE,SHADING,CREATION DATE,CREATOR GROUP,CREATOR SHIFT,LINE,CURRENT QUANTITY,PALLET AGE,PALLET AGE CATEGORY,PALLET MONTH CATEGORY,IS RIMPIL,IS BLOCKED,PALLET STATUS');
      grid.setColumnIds('no,location_subplant,location_area_name,location_area_no,location_line_no,location_id,pallet_no,production_subplant,motif_id,motif_dimension,motif_name,quality,size,shading,creation_date,creator_group,creator_shift,line,current_quantity,pallet_age,pallet_age_category,pallet_month_category,is_rimpil,is_blocked,pallet_status');
      grid.setColSorting('na,str,str,str,str,str,str,str,str,str,str,str,str,str,str,str,str,str,str,str,str,str,str,str,str');
      grid.setColAlign('right,left,left,left,left,left,left,left,left,left,left,left,left,left,left,left,left,left,left,left,left,left,left,left.left');
      grid.setColTypes('cntr,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt');
      grid.setInitWidths('50,80,120,90,90,90,140,90,130,90,200,80,70,70,90,70,70,70,90,90,90,90,80,80,80');
      grid.attachHeader([
        '&nbsp;', FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT]);
      grid.attachFooter(['&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '#stat_total', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;']);
      grid.setNumberFormat("0,000", grid.getColIndexById('current_quantity'), ",", ".");
      grid.init();

      win.progressOn();
      WMSApi.location.getLapAgingDetail(options.locationsubplant, options.dimension, options.isrimpil, options.motif, options.size, options.shading, options.quality)
        .then(requests => requests.map(request => Object.assign(request, { id: request.idnya })))
        .then(requests => {
          win.progressOff();
          grid.clearAll();
          grid.parse(requests, 'js');
        })
        .catch(error => {
          win.progressOff();
          handleApiError(error);
        })

      const buttons = [
        {type: 'button', name: 'close', value: 'Tutup'}
      ];
      const actionFormConfig = [
        {type: 'block', list: buttons}
      ];
      const actionForm = detailsLayout.cells(CELL_DETAILS_ACTIONS).attachForm(actionFormConfig);
      actionForm.attachEvent('onButtonClick', btnName => {
        switch (btnName) {
          case 'close': {
            win.close();
            break;
          }
        }
      });
    }

  </script>
</head>
<body onload="doOnLoad()">

</body>
</html>
