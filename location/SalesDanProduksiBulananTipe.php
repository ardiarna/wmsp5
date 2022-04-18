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

  <title>Sales dan Produksi Bulanan per Tipe</title>
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
  <script src="../assets/js/WMSApi-20211109.js"></script>
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
          {id: CELL_REQUEST_FILTER, header: true, text: 'Laporan Sales dan Produksi Bulanan per Tipe', height: 90},
          {id: CELL_REQUEST_LIST, header: false}
        ]
      });
      windows = new dhtmlXWindows();

      setupRequestFilters(rootLayout.cells(CELL_REQUEST_FILTER));
      fetchAvailableRequests('all', 'P', moment().format('YYYY'), 'M');
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
        {type: "settings", position: "label-left", labelWidth: 60, inputWidth: 160},
        {
          type: 'combo',
          name: 'tiperpt',
          label: '',
          required: true,
          offsetLeft: 20,
          inputWidth: 100,
          hidden: true,
          options: [{text: "Produksi", value: "P"}, {text: "Sales", value: "S"}]
        },
        {type: 'newcolumn'},
        {
          type: 'combo',
          name: 'tahunrpt',
          label: 'Tahun :',
          labelWidth: 47,
          offsetLeft: 20,
          inputWidth: 100,
          options: arrTahun
        },
        {type: 'newcolumn'},
        {
          type: 'combo',
          name: 'gruprpt',
          label: 'Group By :',
          offsetLeft: 20,
          inputWidth: 150,
          options: [{text: "Tipe", value: "T"}, {text: "Tipe dan Quality", value: "Q"}]
        },
        {type: 'newcolumn'},
        {
          type: 'combo',
          name: 'subplant',
          label: 'Subplant :',
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
          const tiperpt = filterForm.getItemValue('tiperpt');
          const tahunrpt = filterForm.getItemValue('tahunrpt');
          const gruprpt = filterForm.getItemValue('gruprpt');
          const subplant = filterForm.getItemValue('subplant');
          fetchAvailableRequests(subplant, tiperpt, tahunrpt, gruprpt);
        }
      });

      return cell;
    }

    function fetchAvailableRequests(subplant, tiperpt, tahunrpt, gruprpt) {
      rootLayout.cells(CELL_REQUEST_LIST).detachToolbar();
      if (gruprpt == 'Q') {
        setupRequest2Grid(rootLayout.cells(CELL_REQUEST_LIST)); 
      } else {
        setupRequestGrid(rootLayout.cells(CELL_REQUEST_LIST));  
      }
      const cell = rootLayout.cells(CELL_REQUEST_LIST);
      cell.progressOn();

      return WMSApi.location.getSalesDanProduksiBulananTipe(subplant, tiperpt, tahunrpt, gruprpt)
        .then(requests => requests.map(request => Object.assign(request, { id: request.idnya })))
        .then(requests => {
          cell.progressOff();
          const toolbar = cell.getAttachedToolbar();
          const grid = cell.getAttachedObject();
          grid.clearAll();

          grid.parse(requests, 'js');
          toolbar.setItemText('timestamp', moment().format());

          if (requests.length === 0) {
            dhtmlx.message('Tidak ada sales dan produksi berdasarkan permintaan yang dipilih!');
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
          // {type: 'button', id: 'export_pdf', text: 'Ke PDF', img: 'fa fa-file-pdf-o'},
          {type: 'spacer'},
          {type: 'text', id: 'timestamp', text: ''}
        ]
      };
      const toolbar = cell.attachToolbar(toolbarConfig);

      toolbar.attachEvent('onClick', itemId => {
        const title = `Laporan Sales dan Produksi Bulanan per Tipe Plant <?= PlantIdHelper::getCurrentPlant() ?>`;
        const exportTitle = `${title}`;
        if(itemId === 'clear_filters') {
          gridUtils.clearAllGridFilters(requestGrid);
        } else if(itemId === 'print') {
          const pdfColWidths = '45,55,150,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90';
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
          const pdfColWidths = '45,55,150,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90';
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
      requestGrid.setHeader('NO.,SUBP.,TIPE,UKURAN,JANUARI,'+COLSPAN+'FEBRUARI,'+COLSPAN+'MARET,'+COLSPAN+'APRIL,'+COLSPAN+'MEI,'+COLSPAN+'JUNI,'+COLSPAN+'JULI,'+COLSPAN+'AGUSTUS,'+COLSPAN+'SEPTEMBER,'+COLSPAN+'OKTOBER,'+COLSPAN+'NOVEMBER,'+COLSPAN+'DESEMBER,'+COLSPAN+'PRODUKSI,'+COLSPAN+'SALES,'+COLSPAN+'CURRENT<br>QTY,REPEAT<br>PROD.',null,
        ['','','','',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'','','']
      );
      requestGrid.attachHeader([ROWSPAN,ROWSPAN,ROWSPAN,ROWSPAN,'PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','TOTAL','PERSEN (%)','TOTAL','PERSEN (%)',ROWSPAN,ROWSPAN
      ]);
      requestGrid.setColumnIds('no,subplant,group_nama,ukuran,janprod,jansale,febprod,febsale,marprod,marsale,aprprod,aprsale,meiprod,meisale,junprod,junsale,julprod,julsale,aguprod,agusale,sepprod,sepsale,oktprod,oktsale,novprod,novsale,desprod,dessale,totprod,senprod,totsale,sensale,stok,repeatprod');
      requestGrid.setColTypes('cntr,rotxt,rotxt,rotxt,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron');
      requestGrid.setInitWidths('45,55,150,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90');
      requestGrid.setColAlign('right,left,left,center,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right');
      requestGrid.setColSorting('na,str,str,str,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int');
      requestGrid.attachHeader([
        '&nbsp;', FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT
      ]);
      requestGrid.attachFooter(['Grand Total', '#cspan', '#cspan', '#cspan', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total',''],
          [TEXT_RIGHT_ALIGN + TEXT_BOLD, '', '', '', TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, '']);
      requestGrid.setColumnColor('#FFF,#FFF,#FFF,#FFF,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#FFF,#F7F7F7,#F7F7F7,#FFF,#FFF');
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('janprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('febprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('marprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('aprprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('meiprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('junprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('julprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('aguprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('sepprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('oktprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('novprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('desprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('totprod'), ",", ".");
      requestGrid.setNumberFormat("0,000.00", requestGrid.getColIndexById('senprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('jansale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('febsale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('marsale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('aprsale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('meisale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('junsale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('julsale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('agusale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('sepsale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('oktsale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('novsale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('dessale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('totsale'), ",", ".");
      requestGrid.setNumberFormat("0,000.00", requestGrid.getColIndexById('sensale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('stok'), ",", ".");
      // requestGrid.enableSmartRendering(true, 100);
      requestGrid.init();
      // requestGrid.splitAt(4)

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
          // {type: 'button', id: 'export_pdf', text: 'Ke PDF', img: 'fa fa-file-pdf-o'},
          {type: 'spacer'},
          {type: 'text', id: 'timestamp', text: ''}
        ]
      };
      const toolbar = cell.attachToolbar(toolbarConfig);

      toolbar.attachEvent('onClick', itemId => {
        const title = `Laporan Sales dan Produksi Bulanan per Tipe Plant <?= PlantIdHelper::getCurrentPlant() ?>`;
        const exportTitle = `${title}`;
        if(itemId === 'clear_filters') {
          gridUtils.clearAllGridFilters(requestGrid);
        } else if(itemId === 'print') {
          const pdfColWidths = '45,55,150,100,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90';
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
          const pdfColWidths = '45,55,150,100,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90';
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
      requestGrid.setHeader('NO.,SUBP.,TIPE,UKURAN,QUALITY,JANUARI,'+COLSPAN+'FEBRUARI,'+COLSPAN+'MARET,'+COLSPAN+'APRIL,'+COLSPAN+'MEI,'+COLSPAN+'JUNI,'+COLSPAN+'JULI,'+COLSPAN+'AGUSTUS,'+COLSPAN+'SEPTEMBER,'+COLSPAN+'OKTOBER,'+COLSPAN+'NOVEMBER,'+COLSPAN+'DESEMBER,'+COLSPAN+'PRODUKSI,'+COLSPAN+'SALES,'+COLSPAN+'CURRENT<br>QTY,REPEAT<br>PROD.',null,
        ['','','','','',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'',TEXT_CENTER,'','','']
      );
      requestGrid.attachHeader([ROWSPAN,ROWSPAN,ROWSPAN,ROWSPAN,ROWSPAN,'PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','PROD.','SALES','TOTAL','PERSEN (%)','TOTAL','PERSEN (%)',ROWSPAN,ROWSPAN
      ]);
      requestGrid.setColumnIds('no,subplant,group_nama,ukuran,quality,janprod,jansale,febprod,febsale,marprod,marsale,aprprod,aprsale,meiprod,meisale,junprod,junsale,julprod,julsale,aguprod,agusale,sepprod,sepsale,oktprod,oktsale,novprod,novsale,desprod,dessale,totprod,senprod,totsale,sensale,stok,repeatprod');
      requestGrid.setColTypes('cntr,rotxt,rotxt,rotxt,rotxt,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron');
      requestGrid.setInitWidths('45,55,150,90,100,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90,90');
      requestGrid.setColAlign('right,left,left,center,left,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right,right');
      requestGrid.setColSorting('na,str,str,str,str,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int,int');
      requestGrid.attachHeader([
        '&nbsp;', FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT
      ]);
      requestGrid.attachFooter(['Grand Total', '#cspan', '#cspan', '#cspan', '#cspan', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total', '#stat_total',''],
          [TEXT_RIGHT_ALIGN + TEXT_BOLD, '', '', '', '', TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, '']);
      requestGrid.setColumnColor('#FFF,#FFF,#FFF,#FFF,#FFF,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#F7F7F7,#FFF,#FFF,#F7F7F7,#F7F7F7,#FFF,#FFF');
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('janprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('febprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('marprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('aprprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('meiprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('junprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('julprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('aguprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('sepprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('oktprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('novprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('desprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('totprod'), ",", ".");
      requestGrid.setNumberFormat("0,000.00", requestGrid.getColIndexById('senprod'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('jansale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('febsale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('marsale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('aprsale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('meisale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('junsale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('julsale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('agusale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('sepsale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('oktsale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('novsale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('dessale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('totsale'), ",", ".");
      requestGrid.setNumberFormat("0,000.00", requestGrid.getColIndexById('sensale'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('stok'), ",", ".");
      // requestGrid.enableSmartRendering(true, 100);
      requestGrid.init();

      return cell;
    }
  </script>
</head>
<body onload="doOnLoad()">

</body>
</html>
