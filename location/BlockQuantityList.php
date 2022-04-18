<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Security\RoleAcl;
use Utils\Env;

SessionUtils::sessionStart();

$user = SessionUtils::getUser();

// check authorization
$authorized = !empty($user->gua_subplant_handover);
if ($authorized) {
  $allowedRoles = RoleAcl::blockQuantity();
  $authorized = UserRole::hasAnyRole($allowedRoles);
}

if (!$authorized) {
  http_response_code(HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
  die('Anda tidak punya akses ke data block quantity!');
}
?>
<!DOCTYPE HTML>
<html lang="id">
<head>

  <title>Daftar Block Quantity</title>
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
  <script src="../assets/js/WMSApi.js"></script>
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
          {id: CELL_REQUEST_FILTER, header: true, text: 'Daftar Block Quantity', height: 90},
          {id: CELL_REQUEST_LIST, header: false}
        ]
      });
      windows = new dhtmlXWindows();

      setupRequestFilters(rootLayout.cells(CELL_REQUEST_FILTER));
      // setupRequestGrid(rootLayout.cells(CELL_REQUEST_LIST));
    }

    function setupRequestFilters(cell) {
      const ALL = {text: 'Semua', value: 'all', selected: true};
      // transform options
      const subplantOptions = [ALL].concat(SUBPLANTS_OPTIONS.map(subplant => ({text: subplant, value: subplant})));
    
      const filterFormConfig = [
        {type: "settings", position: "label-left", labelWidth: 50, inputWidth: 160},
        {
          type: 'combo',
          name: 'tiperpt',
          label: '',
          required: true,
          offsetLeft: 20,
          inputWidth: 150,
          options: [{text: "per Motif", value: "M"}, {text: "per Customer", value: "C"}]
        },
        {type: 'newcolumn'},
        {
          type: 'combo',
          name: 'subplant',
          label: 'Subplant',
          required: true,
          offsetLeft: 20,
          inputWidth: 80,
          options: subplantOptions
        },
        {type: 'newcolumn'},
        {type: 'button', offsetLeft: 20, name: 'getdata', value: 'Dapatkan Data!'}
      ];
      const filterForm = cell.attachForm(filterFormConfig);

      filterForm.attachEvent('onButtonClick', id => {
        if (id === 'getdata') {
          const subplant = filterForm.getItemValue('subplant');
          const tiperpt = filterForm.getItemValue('tiperpt');
          fetchAvailableRequests(subplant, tiperpt);
        }
      });

      return cell;
    }

    function fetchAvailableRequests(subplant, tiperpt) {
      rootLayout.cells(CELL_REQUEST_LIST).detachToolbar();
      if (tiperpt == 'C') {
        setupRequest2Grid(rootLayout.cells(CELL_REQUEST_LIST)); 
      } else {
        setupRequestGrid(rootLayout.cells(CELL_REQUEST_LIST));  
      }
      const cell = rootLayout.cells(CELL_REQUEST_LIST);
      cell.progressOn();

      return WMSApi.location.getBlockQuantityList(subplant, tiperpt)
        .then(requests => requests.map(request => Object.assign(request, { id: request.idnya })))
        .then(requests => {
          cell.progressOff();
          const toolbar = cell.getAttachedToolbar();
          const grid = cell.getAttachedObject();
          grid.clearAll();

          grid.parse(requests, 'js');
          toolbar.setItemText('timestamp', moment().format());

          if (requests.length === 0) {
            dhtmlx.message('Tidak ada daftar block quantity berdasarkan permintaan yang dipilih!');
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
          {type: 'button', id: 'export_pdf', text: 'Ke PDF', img: 'fa fa-file-pdf-o'},
          {type: 'spacer'},
          {type: 'text', id: 'timestamp', text: ''}
        ]
      };
      const toolbar = cell.attachToolbar(toolbarConfig);

      toolbar.attachEvent('onClick', itemId => {
        const title = `Daftar Block Quantity Plant <?= PlantIdHelper::getCurrentPlant() ?>`;
        const exportTitle = `${title}`;
        if(itemId === 'clear_filters') {
          gridUtils.clearAllGridFilters(requestGrid);
        } else if(itemId === 'print') {
          const pdfColWidths = '25,25,100,170,40,30,40,50,50,170';
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
          const pdfColWidths = '25,25,100,170,40,30,40,50,50,170';
          cell.progressOn();
          const filename = exportTitle + '.pdf';
          gridUtils.generateFilteredPdf(requestGrid, title, USERID, pdfColWidths)
            .download(filename);
          cell.progressOff();
        }
      });

      // initialize grid
      const FILTERS = gridUtils.headerFilters;
      const requestGrid = cell.attachGrid();
      requestGrid.setHeader('NO.,SUBP.,MOTIF ID,MOTIF NAME,QUALITY,SIZE,SHADE,AVAILABLE QTY,TOTAL BLOCK QTY,ORDER NUMBER(QTY)');
      requestGrid.setColumnIds('no,subplant,motif_id,motif_name,quality,size,shading,qty_ava,qty_block,order_id');
      requestGrid.setColTypes('cntr,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,ron,ron,rotxt');
      requestGrid.setInitWidths('45,45,120,250,80,50,60,120,120,*');
      requestGrid.setColAlign('right,left,left,left,center,left,left,right,right,left');
      requestGrid.setColSorting('na,str,str,str,str,str,str,int,int,str');
      requestGrid.attachHeader([
        '&nbsp;', FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT
      ]);
      requestGrid.attachFooter(['Grand Total', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#stat_total', '#stat_total', ''],
          [TEXT_RIGHT_ALIGN + TEXT_BOLD, '', '', '', '', '', '', TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, '']);
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('qty_ava'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('qty_block'), ",", ".");
      requestGrid.enableSmartRendering(true, 100);
      requestGrid.init();

      return cell;
    }

    function setupRequest2Grid(cell) {
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
          {type: 'button', id: 'export_pdf', text: 'Ke PDF', img: 'fa fa-file-pdf-o'},
          {type: 'spacer'},
          {type: 'text', id: 'timestamp', text: ''}
        ]
      };
      const toolbar = cell.attachToolbar(toolbarConfig);

      toolbar.attachEvent('onClick', itemId => {
        const title = `Daftar Block Quantity Plant <?= PlantIdHelper::getCurrentPlant() ?>`;
        const exportTitle = `${title}`;
        if(itemId === 'clear_filters') {
          gridUtils.clearAllGridFilters(requestGrid);
        } else if(itemId === 'print') {
          const pdfColWidths = '25,250,60,400';
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
          const pdfColWidths = '25,250,60,400';
          cell.progressOn();
          const filename = exportTitle + '.pdf';
          gridUtils.generateFilteredPdf(requestGrid, title, USERID, pdfColWidths)
            .download(filename);
          cell.progressOff();
        }
      });

      // initialize grid
      const FILTERS = gridUtils.headerFilters;
      const requestGrid = cell.attachGrid();
      requestGrid.setHeader('NO.,CUSTOMER,TOTAL BLOCK QTY,ORDER NUMBER(QTY)');
      requestGrid.setColumnIds('no,customer_id,qty_block,order_id');
      requestGrid.setColTypes('cntr,rotxt,ron,rotxt');
      requestGrid.setInitWidths('45,350,120,*');
      requestGrid.setColAlign('right,left,right,left');
      requestGrid.setColSorting('na,str,int,str');
      requestGrid.attachHeader([
        '&nbsp;', FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT
      ]);
      requestGrid.attachFooter(['Grand Total', '#cspan', '#stat_total', ''], [TEXT_RIGHT_ALIGN + TEXT_BOLD, '',  TEXT_RIGHT_ALIGN + TEXT_BOLD, '']);
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('qty_block'), ",", ".");
      requestGrid.enableSmartRendering(true, 100);
      requestGrid.init();

      requestGrid.attachEvent('onRowSelect', rowId => {
        openNewRequestWindow(windows, rowId);
      });

      return cell;
    }

    function openNewRequestWindow(windows, customer_id) {
      const win = windows.createWindow('win_dtl', 0, 0, 700, 500);
      win.centerOnScreen();
      win.setText('Detail Block Quantity - '+customer_id);
      win.button('park').hide();
      win.button('minmax').hide();
      win.setModal(true);

      const FILTERS = gridUtils.headerFilters;
      const detailGrid = win.attachGrid();
      detailGrid.setHeader('NO.,PALLET,MOTIF,QLTY,SIZE,SHADE,BLOCK QTY');
      detailGrid.setColumnIds('no,pallet_no,motif_name,quality,size,shading,qty_block');
      detailGrid.setColTypes('cntr,rotxt,rotxt,rotxt,rotxt,rotxt,ron');
      detailGrid.setInitWidths('45,135,240,50,50,50,80');
      detailGrid.setColAlign('right,center,left,center,left,left,right');
      detailGrid.setColSorting('na,str,str,str,str,str,int');
      detailGrid.attachHeader([
        '&nbsp;', FILTERS.TEXT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT
      ]);
      detailGrid.attachFooter(['Total', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#stat_total'], [TEXT_RIGHT_ALIGN + TEXT_BOLD, '', '', '', '', '', TEXT_RIGHT_ALIGN + TEXT_BOLD]);
      detailGrid.setNumberFormat("0,000", detailGrid.getColIndexById('qty_block'), ",", ".");
      detailGrid.enableSmartRendering(true, 100);
      detailGrid.init();

      return WMSApi.location.getBlockQuantityListDetail(customer_id)
        .then(requests => requests.map(request => Object.assign(request, { id: request.pallet_no })))
        .then(requests => {
          detailGrid.clearAll();
          detailGrid.parse(requests, 'js');
        })
        .catch(error => {
          cell.progressOff();
          handleApiError(error);
        })
    }
  </script>
</head>
<body onload="doOnLoad()">

</body>
</html>
