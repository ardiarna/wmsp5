<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

SessionUtils::sessionStart();
$user = SessionUtils::getUser();
?>
<!DOCTYPE HTML>
<html lang="id">
<head>
  <meta charset="utf-8"/>
  <title>Masuk/Pindah Palet</title>
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
  <script src="../assets/libs/lodash/lodash.min.js"></script>

  <script src="../assets/js/date-utils.js"></script>
  <script src="../assets/js/WMSApi-20190711-01.js"></script>
  <script src="../assets/js/grid-utils-20190704-01.js"></script>
  <script src="../assets/js/grid-custom-types-20190704-01.js"></script>
  <script>
    const addPallet = (function (moment, gridUtils, Cookies, WMSApi, _) {
      WMSApi.setBaseUrl('../api');
      const DEFAULT_MESSAGE_BOX_EXPIRE_MS = 5000;

      const CELL_SCAN_PALLET_FORM = 'a';
      const CELL_PALLETS_PER_LINE_GRID = 'b';
      const USERID = '<?= $user->gua_kode ?>';

      const STYLES = gridUtils.styles;
      const FILTERS = gridUtils.headerFilters;

      let grid_palletsPerLine, layout_root;
      let form_scanPallet;

      // noinspection JSAnnotator
      const PALLET_ID_REGEX = <?= PlantIdHelper::palletIdRegex() ?>;
      // noinspection JSAnnotator
      const LOCATION_ID_REGEX = <?= PlantIdHelper::locationRegex() ?>;

      let locationIdValid = false;
      let locationDetailsLoaded = false;
      const gridLocation = {
        subplant: '',
        areaNo: '',
        areaName: '',
        lineNo: -1,
        <?php if (PlantIdHelper::usesLocationCell()): ?>
        cellNo: -1
        <?php endif ?>
      };

      let palletNoValid = false;
      let palletDetailsLoaded = false;
      let palletHasLocation = false;

      let form_auto = false;

      function clearPalletDetails() {
        // clear the details form
        form_scanPallet.setItemValue('location_id', '');
        form_scanPallet.setItemValue('motif_id', '');
        form_scanPallet.setItemValue('motif_dimension', '');
        form_scanPallet.setItemValue('motif_name', '');
        form_scanPallet.setItemValue('quality', '');
        form_scanPallet.setItemValue('size', '');
        form_scanPallet.setItemValue('shading', '');
        form_scanPallet.setItemValue('current_quantity', '');

        palletDetailsLoaded = false;
        palletHasLocation = false;
        palletNoValid = false;
      }

      function doOnLoad() {
        layout_root = new dhtmlXLayoutObject({
          parent: document.body,
          pattern: '2U',
          cells: [
            {id: CELL_SCAN_PALLET_FORM, text: 'Pindai Palet dan Kode Lokasi', height: 90},
            {id: CELL_PALLETS_PER_LINE_GRID, text: 'Daftar Palet'}
          ]
        });

        // setup selectLine part
        form_scanPallet = layout_root.cells(CELL_SCAN_PALLET_FORM).attachForm([
          {type: "settings", position: "label-left", labelWidth: 120, inputWidth: 400},
          {
            type: "block", inputWidth: "auto", offsetTop: 12, list: [
              {
                type: "checkbox",
                name: "mode_auto",
                label: "Otomatis"
              },
              {
                type: "input",
                name: "pallet_no",
                label: "No. Palet",
                maxLength: 18,
                style: "text-transform: uppercase; font-size: 40px !important;"
              },
              {
                type: "input",
                name: "new_location_id",
                label: "Kd. Lok.",
                maxLength: 8,
                style: "text-transform: uppercase; font-size: 40px !important;"
              },
              {type: "label", label: "Info Palet"},
              {type: "input", name: "location_id", label: "Lokasi", readonly: true},
              {type: "input", name: "motif_id", label: "Kode Motif", readonly: true},
              {type: "input", name: "motif_dimension", label: "Dimensi", readonly: true},
              {type: "input", name: "motif_name", label: "Nama Motif", readonly: true},
              {type: "input", name: "quality", label: "Kualitas", readonly: true},
              {type: "input", name: "size", label: "Size", readonly: true},
              {type: "input", name: "shading", label: "Shading", readonly: true},
              {type: "input", name: "current_quantity", label: "Qty."},

              {type: "hidden", name: "location_subplant"},
              {type: "hidden", name: "location_line_no"},
            ]
          }
        ]);
        form_scanPallet.setFontSize('16px');

        form_scanPallet.attachEvent('onChange', function (name, value, state) {
          if (name === 'mode_auto') {
            form_auto = state;
          }
        });
        form_scanPallet.attachEvent('onKeyup', _.debounce(function (inp, ev, id, value) {
          if (id === 'pallet_no' || id === 'new_location_id') {
            form_scanPallet.setItemValue(id, form_scanPallet.getItemValue(id).toUpperCase());
          }

          // do processing on the form.
          if (id === 'pallet_no') {
            if (palletDetailsLoaded) {
              clearPalletDetails();
            }

            // do validation
            const palletNo = form_scanPallet.getItemValue(id);
            palletNoValid = PALLET_ID_REGEX.test(palletNo);
            if (palletNoValid) {
              getPalletInfo(palletNo);
            }
          } else if (id === 'new_location_id') {
            resetLocationDetailsGrid();

            // do validation.
            const locationId = form_scanPallet.getItemValue(id);
            locationIdValid = LOCATION_ID_REGEX.test(locationId);
            if (locationIdValid) {
              WMSApi.location.getLocationInfo(locationId)
                .then(location => {
                  gridLocation.subplant = location.plant_code;
                  gridLocation.areaNo = location.area_code;
                  gridLocation.areaName = location.area_name;
                  gridLocation.lineNo = location.line_no;
                  <?php if (PlantIdHelper::usesLocationCell()): ?>
                  gridLocation.cellNo = location.cell_no;
                  <?php endif ?>
                  fetchPallets(gridLocation.subplant, gridLocation.areaNo, gridLocation.lineNo);
                })
                .catch(error => {
                  if (form_auto) {
                    dhtmlx.message({
                      type: "alert-warning",
                      text: error,
                      expire: DEFAULT_MESSAGE_BOX_EXPIRE_MS
                    });
                    clearNewLocationId();
                    resetLocationDetailsGrid();
                  } else {
                    dhtmlx.alert({
                      type: "alert-warning",
                      text: error,
                      title: 'Error',
                      callback: () => {
                        clearNewLocationId();
                        resetLocationDetailsGrid();
                      }
                    })
                  }
                })
            }
          }
        }, 100));
        form_scanPallet.setItemFocus('pallet_no');

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
          const subplant = gridLocation.subplant;
          const areaCode = gridLocation.areaNo;
          const areaName = gridLocation.areaName;
          const lineNo = gridLocation.lineNo;

          const title = `Daftar Palet di Subplant ${subplant} - Area ${areaCode} - ${areaName} - Baris ${lineNo}`;
          const pdfWidths = '30,60,120,80,45,60,*,75,30,40,30,60,40,120';
          switch (itemId) {
            case 'refresh':
              fetchPallets(subplant, areaCode, lineNo);
              break;
            case 'clear_filters':
              gridUtils.clearAllGridFilters(grid_palletsPerLine);
              break;
            case 'print':
              gridUtils.generateFilteredPdf(grid_palletsPerLine, title, USERID, pdfWidths)
                .print();
              break;
            case 'export_csv':
              const csvTitle = `${moment().format('YYYY-MM-DD')} - ${title}`;
              gridUtils.downloadFilteredCSV(grid_palletsPerLine, csvTitle);
              break;
            case 'export_pdf':
              const pdfTitle = `${moment().format('YYYY-MM-DD')} - ${title}`;
              gridUtils.generateFilteredPdf(grid_palletsPerLine, title, USERID, pdfWidths)
                .download(pdfTitle);
              break;
          }
        });

        grid_palletsPerLine = layout_root.cells(CELL_PALLETS_PER_LINE_GRID).attachGrid();
        grid_palletsPerLine.setHeader("BRS.,KD. LOK.,NO. PLT.,KD. MOTIF,DIM.,QLTY.,MOTIF,TGL. PROD.,LINE,SHIFT,SIZE,SHADING,QTY.,SEJAK", null,
          [STYLES.TEXT_RIGHT_ALIGN, '', '', '', '', '', '', STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_RIGHT_ALIGN, '', '', STYLES.TEXT_RIGHT_ALIGN, '']);
        grid_palletsPerLine.setColumnIds('location_line_no,location_id,pallet_no,motif_id,motif_dimension,quality,motif_name,created_at,line,creator_shift,size,shading,current_quantity,location_since');
        grid_palletsPerLine.setColTypes('ron,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,ro_date,ron,ron,rotxt,rotxt,ron,ro_ts');
        grid_palletsPerLine.setInitWidths("45,70,120,80,50,80,*,80,50,50,40,65,50,120");
        grid_palletsPerLine.setColAlign("right,left,left,left,left,left,left,left,right,right,left,left,right,left");
        grid_palletsPerLine.setColSorting("int,str,str,str,str,str,str,str,int,int,str,str,int,str");
        grid_palletsPerLine.attachHeader([FILTERS.NUMERIC, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.NUMERIC, FILTERS.TEXT]);
        grid_palletsPerLine.attachFooter(['', 'Total', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', gridUtils.reducers.STATISTICS_COUNT, gridUtils.reducers.STATISTICS_TOTAL, '']
          , ['', STYLES.TEXT_RIGHT_ALIGN + STYLES.TEXT_BOLD, '', '', '', '', '', '', '', '', '', STYLES.TEXT_RIGHT_ALIGN + STYLES.TEXT_BOLD, STYLES.TEXT_RIGHT_ALIGN + STYLES.TEXT_BOLD, '']);

        grid_palletsPerLine.setColumnHidden(grid_palletsPerLine.getColIndexById('motif_id'), true);
        grid_palletsPerLine.setColumnHidden(grid_palletsPerLine.getColIndexById('location_since'), true);
        grid_palletsPerLine.setColumnHidden(grid_palletsPerLine.getColIndexById('location_line_no'), true);
        <?php if (!PlantIdHelper::usesLocationCell()): ?>
        grid_palletsPerLine.setColumnHidden(grid_palletsPerLine.getColIndexById('location_id'), true);
        <?php endif ?>

        grid_palletsPerLine.setNumberFormat("0,000", grid_palletsPerLine.getColIndexById('current_quantity'), ".", ",");
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
            grid_palletsPerLine.parse(data, 'js');
            locationDetailsLoaded = true;

            const cell = layout_root.cells(CELL_PALLETS_PER_LINE_GRID);
            cell.setText(`Daftar Palet (${gridLocation.subplant} - ${gridLocation.areaNo} - ${gridLocation.areaName} - Baris ${gridLocation.lineNo})`);
            cell.progressOff();
          })
          .then(() => {
            if (palletDetailsLoaded && locationIdValid) {
              // add pallet.
              const palletNo = form_scanPallet.getItemValue('pallet_no');
              const locationId = form_scanPallet.getItemValue('new_location_id');
              addPalletToLocation(palletNo, locationId)
            } else {
              form_scanPallet.setItemFocus('pallet_no');
            }
          })
          .catch(error => {
            layout_root.cells(CELL_PALLETS_PER_LINE_GRID).progressOff();
            locationIdValid = false;

            let errorMessage;
            if (error instanceof Object && error.hasOwnProperty('message')) {
              errorMessage = error.message;
            } else {
              errorMessage = error;
            }
            if (form_auto) {
              dhtmlx.message({
                type: "alert-warning",
                text: errorMessage,
                expire: DEFAULT_MESSAGE_BOX_EXPIRE_MS
              });
              clearNewLocationId();
              resetLocationDetailsGrid();
            } else {
              dhtmlx.alert({
                type: "alert-warning",
                text: errorMessage,
                title: 'Error',

                // isConfirmed is ignored here, since the result will be always 'true'
                callback: () => {
                  clearNewLocationId();
                  resetLocationDetailsGrid();
                }
              })
            }
          })
      }

      function getPalletInfo(palletNo) {
        layout_root.cells(CELL_SCAN_PALLET_FORM).progressOn();
        return WMSApi.stock.getPalletInfo(palletNo)
          .then(pallet => {
            // set pallet values
            form_scanPallet.setItemValue('location_id', pallet.location ? pallet.location.no : null);
            form_scanPallet.setItemValue('motif_id', pallet.motif.id);
            form_scanPallet.setItemValue('motif_dimension', pallet.motif.dimension);
            form_scanPallet.setItemValue('motif_name', pallet.motif.name);
            form_scanPallet.setItemValue('quality', pallet.quality);
            form_scanPallet.setItemValue('size', pallet.size);
            form_scanPallet.setItemValue('shading', pallet.shading);
            form_scanPallet.setItemValue('current_quantity', pallet.current_quantity);

            palletHasLocation = pallet.location !== null;
            palletDetailsLoaded = true;
            layout_root.cells(CELL_SCAN_PALLET_FORM).progressOff();

            return pallet;
          })
          .then(pallet => {
            // validation part
            if (pallet.stwh === null) {
              throw new Error(`Palet ${pallet.pallet_no} belum diserahterimakan!`);
            }
            if (pallet.current_quantity === 0) {
              throw new Error(`Palet ${pallet.pallet_no} kosong!`);
            }

            if (palletDetailsLoaded && locationIdValid) {
              const locationId = form_scanPallet.getItemValue('new_location_id');
              addPalletToLocation(palletNo, locationId);
            } else {
              form_scanPallet.setItemFocus('new_location_id');
            }
          })
          .catch(error => {
            layout_root.cells(CELL_SCAN_PALLET_FORM).progressOff();
            palletNoValid = false;

            let errorMessage;
            if (error instanceof Object && error.hasOwnProperty('message')) {
              errorMessage = error.message;
            } else {
              errorMessage = error;
            }
            if (form_auto) {
              dhtmlx.message({
                type: "alert-warning",
                text: errorMessage,
                expire: DEFAULT_MESSAGE_BOX_EXPIRE_MS
              });
              clearPalletNo();
            } else {
              dhtmlx.alert({
                type: "alert-warning",
                text: errorMessage,
                title: 'Error',
                callback: () => {
                  clearPalletNo();
                }
              })
            }
          })
      }

      function clearPalletNo() {
        form_scanPallet.setItemValue('pallet_no', '');
        palletNoValid = false;
      }

      function clearNewLocationId() {
        form_scanPallet.setItemValue('new_location_id', '');
        locationIdValid = false;
      }

      function resetLocationDetailsGrid() {
        grid_palletsPerLine.clearAll();

        gridLocation.subplant = '';
        gridLocation.areaNo = '';
        gridLocation.areaName = '';
        gridLocation.lineNo = -1;
        <?php if (PlantIdHelper::usesLocationCell()): ?>
        gridLocation.cellNo = -1;
        <?php endif ?>
        layout_root.cells(CELL_PALLETS_PER_LINE_GRID).setText('Daftar Palet');
      }

      function addPalletToLocation(palletNo, locationId, checkForCurrentLocation = true) {
        if (palletHasLocation && checkForCurrentLocation && !form_auto) {
          const currentLocationId = form_scanPallet.getItemValue('location_id');
          dhtmlx.confirm({
            title: 'Konfirmasi Pindah Palet',
            text: `Palet ${palletNo} sudah berada di ${currentLocationId}. Apakah Anda yakin ingin memindahkannya ke ${locationId}?`,
            callback: isConfirmed => {
              if (isConfirmed) {
                addPalletToLocation(palletNo, locationId, false)
              } else {
                dhtmlx.alert({
                  title: 'Pindah Palet Batal',
                  text: `Pemindahan ${palletNo} ke ${locationId} batal.`,
                  callback: () => {
                    clearPalletNo();
                    form_scanPallet.setItemFocus('pallet_no');
                  }
                })
              }
            }
          });
          return null;
        }

        return WMSApi.location.addPalletsToLocation([palletNo], locationId)
          .then(responseObject => {
            clearPalletNo();
            clearNewLocationId();
            clearPalletDetails();
            form_scanPallet.setItemFocus('pallet_no');

            dhtmlx.message({
              text: responseObject.msg,
              expire: DEFAULT_MESSAGE_BOX_EXPIRE_MS
            });
            fetchPallets(gridLocation.subplant, gridLocation.areaNo, gridLocation.lineNo);
          })
          .catch(error => {
            layout_root.cells(CELL_SCAN_PALLET_FORM).progressOff();

            let errorMessage;
            if (error instanceof Object && error.hasOwnProperty('message')) {
              errorMessage = error.message;
            } else {
              errorMessage = error;
            }
            if (form_auto) {
              dhtmlx.message({
                type: "alert-warning",
                text: errorMessage,
                expire: DEFAULT_MESSAGE_BOX_EXPIRE_MS
              })
            } else {
              dhtmlx.alert({
                type: "alert-warning",
                text: errorMessage,
                title: 'Error'
              })
            }
          })
      }

      return {doOnLoad}
    })(moment, gridUtils, Cookies, WMSApi, _);
  </script>
</head>
<body onload="addPallet.doOnLoad()">

</body>
</html>
