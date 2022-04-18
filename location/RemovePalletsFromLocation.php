<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

SessionUtils::sessionStart();
$user = SessionUtils::getUser();
?>
<!DOCTYPE HTML>
<html lang="id">
<head>
  <title>Hapus Palet dari Lokasi</title>
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
    const movePallets = (function (moment, gridUtils, Cookies, WMSApi) {
      WMSApi.setBaseUrl('../api');

      const CELL_LOCATION_FORM = 'a';
      const CELL_PALLETS_PER_LOCATION_GRID = 'b';
      const USERID = '<?= $user->gua_kode ?>';

      const STYLES = gridUtils.styles;
      const FILTERS = gridUtils.headerFilters;

      let grid_palletsByLocation, layout_root;
      let form_removePallets;

      // noinspection JSAnnotator
      const LOCATION_ID_REGEX = <?= PlantIdHelper::locationRegex() ?>;

      // flags for the form inputs.
      let gridLoaded = false;
      let locationIdValid = false;
      let hasValidReason = false;

      const palletsToRemove = [];

      /**
       * Check whether the form is valid for moving pallets.
       * @return boolean
       */
      function canDeletePallets() {
        return palletsToRemove.length > 0 && hasValidReason && locationIdValid;
      }

      /**
       * Handles error by displaying proper error message (using dhtmlx)
       * @param {Error|string} error the error to be displayed, either as HTML or plain text.
       * @param {function} callback function to be called after the error message has been displayed (e.g. cleanups/undo operations).
       */
      function handleError(error, callback = () => {
      }) {
        dhtmlx.alert({
          type: "alert-warning",
          text: error,
          title: 'Error',
          callback: callback
        })
      }

      function clearPalletsGrid() {
        grid_palletsByLocation.clearAll();
        const toolbar = layout_root.cells(CELL_PALLETS_PER_LOCATION_GRID).getAttachedToolbar();
        toolbar.forEachItem(itemId => {
          toolbar.disableItem(itemId);
        });
        toolbar.setItemText('timestamp', '');
        gridLoaded = false;
      }

      function resetForm() {
        clearLocationId();

        palletsToRemove.splice(0, palletsToRemove.length);
        form_removePallets.setItemValue('reason', '');
        hasValidReason = false;

        form_removePallets.disableItem('remove_pallets');
      }

      let getLocationInProgress = false;
      let removePalletsInProgress = false;
      function doOnLoad() {
        layout_root = new dhtmlXLayoutObject({
          parent: document.body,
          pattern: '2E',
          cells: [
            {id: CELL_LOCATION_FORM, text: 'Masukkan Lokasi', height: 180},
            {id: CELL_PALLETS_PER_LOCATION_GRID, text: 'Daftar Palet'}
          ]
        });

        // setup selectLocations part
        form_removePallets = layout_root.cells(CELL_LOCATION_FORM).attachForm([
          {type: "settings", position: "label-left", labelWidth: 80, inputWidth: 160},
          {
            type: "block", inputWidth: "auto", offsetTop: 0, width: 400, blockOffset: 0, list: [
              {
                type: 'input',
                name: 'location_id',
                label: 'Kd. Lok.',
                maxLength: 8,
                style: "text-transform: uppercase; font-size: 24px !important;"
              },
              {
                type: 'input',
                rows: 2,
                name: 'reason',
                label: 'Alasan',
                inputWidth: 250,
                style: "font-size: 24px !important;"
              },
            ]
          },
          {type: 'newcolumn'},
          {type: 'button', name: 'remove_pallets', value: 'Hapus Lokasi Palet', disabled: true}
        ]);
        form_removePallets.setFontSize('16px');
        form_removePallets.attachEvent('onButtonClick', buttonId => {
          if (buttonId === 'remove_pallets') {
            const locationId = form_removePallets.getItemValue('location_id');
            dhtmlx.confirm({
              title: 'Konfirmasi Hapus Palet',
              text: `Apakah Anda yakin ingin menghapus ${palletsToRemove.length} palet dari ${locationId}?`,
              // isConfirmed is a boolean, which is true (OK)/false (Cancel) based on the selected response in the modal box.
              // refer back to DHTMLX docs about callbacks in message boxes.
              callback: isConfirmed => {
                if (isConfirmed) {
                  const reason = form_removePallets.getItemValue('reason');
                  removePallets(palletsToRemove, reason);
                }
              }
            })
          }
        });

        form_removePallets.attachEvent('onKeyup', _.debounce((inp, ev, id, value) => {
          if (id === 'location_id') {
            form_removePallets.setItemValue(id, form_removePallets.getItemValue(id).toUpperCase());

            locationIdValid = false;
            form_removePallets.disableItem('remove_pallets');
            if (gridLoaded) {
              palletsToRemove.splice(0, palletsToRemove.length);
              clearPalletsGrid();
            }

            // do validation
            const locationId = form_removePallets.getItemValue(id);
            if (LOCATION_ID_REGEX.test(locationId)) {
              if (getLocationInProgress) {
                return;
              }

              getLocationInfo(locationId)
                .then(location => {
                  // get the pallets, since the location has been verified
                  fetchPallets(locationId)
                    .then(() => {
                      locationIdValid = true;
                      // properly enable the forms
                      if (canDeletePallets()) {
                        form_removePallets.enableItem('remove_pallets');
                      } else {
                        form_removePallets.setItemFocus('reason');
                      }
                    })
                    .then(() => {
                      getLocationInProgress = false;
                    })
                    .catch(error => {
                      handleError(error, () => {
                        getLocationInProgress = false;
                        clearLocationId(id);
                        form_removePallets.setItemFocus('location_id');
                      })
                    })
                })
            }
          } else if (id === 'reason') {
            const reason = form_removePallets.getItemValue(id);
            hasValidReason = (reason && reason.trim().length > 0);

            if (canDeletePallets()) {
              form_removePallets.enableItem('remove_pallets');
            } else {
              form_removePallets.disableItem('remove_pallets');
            }
          }
        }, 100));

        const toolbar_palletsByLocation = layout_root.cells(CELL_PALLETS_PER_LOCATION_GRID).attachToolbar({
          iconset: 'awesome',
          items: [
            {type: 'button', id: 'refresh', text: 'Segarkan', img: 'fa fa-refresh', disabled: true},
            {type: 'separator'},
            {type: 'button', id: 'clear_filters', text: 'Bersihkan Penyaring Data', img: 'fa fa-close', disabled: true},
            {type: 'separator'},
            {type: 'button', id: 'print', text: 'Cetak', img: 'fa fa-print', disabled: true},
            {type: 'button', id: 'export_csv', text: 'Ke CSV', img: 'fa fa-file-excel-o', disabled: true},
            {type: 'button', id: 'export_pdf', text: 'Ke PDF', img: 'fa fa-file-pdf-o', disabled: true},
            {type: 'spacer'},
            {type: 'text', id: 'timestamp', text: ''}
          ]
        });
        toolbar_palletsByLocation.attachEvent('onClick', itemId => {
          const locationId = form_removePallets.getItemValue('location_id');
          let title = `Daftar Palet di ${locationId}`;

          const pdfWidths = '30,120,80,45,60,*,75,30,40,30,60,40,120';
          switch (itemId) {
            case 'refresh':
              fetchPallets(locationId);
              break;
            case 'clear_filters':
              gridUtils.clearAllGridFilters(grid_palletsByLocation);
              break;
            case 'print':
              gridUtils.generateFilteredPdf(grid_palletsByLocation, title, USERID, pdfWidths)
                .print();
              break;
            case 'export_csv':
              const csvTitle = `${moment().format('YYYY-MM-DD')} - ${title}`;
              gridUtils.downloadFilteredCSV(grid_palletsByLocation, csvTitle);
              break;
            case 'export_pdf':
              const pdfTitle = `${moment().format('YYYY-MM-DD')} - ${title}`;
              gridUtils.generateFilteredPdf(grid_palletsByLocation, title, USERID, pdfWidths)
                .download(pdfTitle);
              break;
          }
        });

        // setup grid
        grid_palletsByLocation = layout_root.cells(CELL_PALLETS_PER_LOCATION_GRID).attachGrid();
        grid_palletsByLocation.setImagesPath('../assets/libs/dhtmlx/imgs/');
        grid_palletsByLocation.setHeader("PINDAH,NO. PLT.,KD. MOTIF,DIM.,QLTY.,MOTIF,TGL. PROD.,LINE,SHIFT,SIZE,SHADING,QTY.,SEJAK", null,
          [STYLES.TEXT_CENTER_ALIGN, '', '', '', '', '', '', STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_RIGHT_ALIGN, '', '', STYLES.TEXT_RIGHT_ALIGN, '']);
        grid_palletsByLocation.setColumnIds('is_checked,pallet_no,motif_id,motif_dimension,quality,motif_name,created_at,line,creator_shift,size,shading,current_quantity,location_since');
        grid_palletsByLocation.setColTypes('ch,rotxt,rotxt,rotxt,rotxt,rotxt,ro_date,ron,ron,rotxt,rotxt,ron,ro_ts');
        grid_palletsByLocation.setInitWidths("70,120,80,50,80,*,80,50,50,40,65,50,120");
        grid_palletsByLocation.setColAlign("center,left,left,left,left,left,left,right,right,left,left,right,left");
        grid_palletsByLocation.setColSorting("na,str,str,str,str,str,str,int,int,str,str,int,str");
        grid_palletsByLocation.attachHeader([FILTERS.CHECKBOX, FILTERS.TEXT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.NUMERIC, FILTERS.TEXT]);
        grid_palletsByLocation.attachFooter(['Total', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', gridUtils.reducers.STATISTICS_COUNT, gridUtils.reducers.STATISTICS_TOTAL, '']
          , [STYLES.TEXT_RIGHT_ALIGN + STYLES.TEXT_BOLD, '', '', '', '', '', '', '', '', '', STYLES.TEXT_RIGHT_ALIGN + STYLES.TEXT_BOLD, STYLES.TEXT_RIGHT_ALIGN + STYLES.TEXT_BOLD, '']);

        grid_palletsByLocation.setColumnHidden(grid_palletsByLocation.getColIndexById('motif_id'), true);
        grid_palletsByLocation.setNumberFormat("0,000", grid_palletsByLocation.getColIndexById('current_quantity'), ".", ",");
        grid_palletsByLocation.attachEvent("onXLS", function () {
          layout_root.cells(CELL_PALLETS_PER_LOCATION_GRID).progressOn();
        });
        grid_palletsByLocation.attachEvent("onXLE", function () {
          layout_root.cells(CELL_PALLETS_PER_LOCATION_GRID).progressOff()
        });

        grid_palletsByLocation.attachEvent('onCheck', (rowId, cellIdx, isChecked) => {
          if (isChecked) {
            if (!palletsToRemove.includes(rowId)) {
              palletsToRemove.push(rowId);
            }
          } else {
            // rowId = palletNo
            const idx = palletsToRemove.indexOf(rowId);
            if (idx > -1) {
              palletsToRemove.splice(idx, 1);
            }
          }

          if (canDeletePallets()) {
            form_removePallets.enableItem('remove_pallets');
          } else {
            form_removePallets.disableItem('remove_pallets');
          }
        });

        grid_palletsByLocation.enableSmartRendering(true, 100);
        grid_palletsByLocation.init();
      }

      /**
       * Clears out the form (and the grid) of the screen.
       **/
      function clearLocationId() {
        form_removePallets.setItemValue('location_id', '');
        locationIdValid = false;
        clearPalletsGrid();
      }

      /**
       * Default expiry time for DHTMLX message box, in milliseconds.
       * @var {int}
       **/
      const DEFAULT_MESSAGE_BOX_EXPIRE_MS = 5000;

      function getLocationInfo(locationId) {
        return WMSApi.location.getLocationInfo(locationId)
      }

      /**
       * Fetch pallets placed on a certain location.
       * @param {string} locationId location to fetch from.
       **/
      function fetchPallets(locationId) {
        layout_root.cells(CELL_PALLETS_PER_LOCATION_GRID).progressOn();
        return WMSApi.location.fetchPalletsByLocationId(locationId)
          .then(pallets => {
            if (pallets.length === 0) {
              // no pallet to move
              throw new Error(`Tidak ada palet di ${locationId}!`);
            }

            return pallets.map(pallet => ({
              id: pallet.pallet_no,
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
            }));
          })
          .then(data => {
            gridUtils.clearAllGridFilters(grid_palletsByLocation);
            grid_palletsByLocation.clearAll();
            grid_palletsByLocation.parse(data, 'js');

            gridLoaded = true;
            const cell = layout_root.cells(CELL_PALLETS_PER_LOCATION_GRID);
            cell.setText(`Daftar Palet (${locationId})`);
            cell.progressOff();

            const toolbar = cell.getAttachedToolbar();
            toolbar.setItemText('timestamp', gridUtils.date.getCurrentLocaleDateTime());
            toolbar.forEachItem(itemId => {
              toolbar.enableItem(itemId);
            })
          })
          .catch(error => {
            layout_root.cells(CELL_PALLETS_PER_LOCATION_GRID).progressOff();
            throw error;
          })
      }

      /**
       * Removes pallets from their location.
       * @param {Array} palletNos pallets to remove
       * @param {string} reason reason for removal
       */
      function removePallets(palletNos, reason) {
        if (removePalletsInProgress) {
          return;
        }

        layout_root.cells(CELL_LOCATION_FORM).progressOn();
        WMSApi.location.removePalletsFromLocation(palletNos, reason)
          .then(response => { // display message
            layout_root.cells(CELL_LOCATION_FORM).progressOff();

            dhtmlx.message({
              text: response.msg,
              expire: DEFAULT_MESSAGE_BOX_EXPIRE_MS
            });
          })
          .then(() => { // clear form and start from beginning again.
            resetForm();

            form_removePallets.setItemFocus('location_id');
          })
          .catch(error => {
            layout_root.cells(CELL_LOCATION_FORM).progressOff();
            handleError(error, () => {
              removePalletsInProgress = false;
            })
          })
      }

      return {doOnLoad}
    })(moment, gridUtils, Cookies, WMSApi);
  </script>
</head>
<body onload="movePallets.doOnLoad()">

</body>
</html>
