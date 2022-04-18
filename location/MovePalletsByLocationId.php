<?php
require_once dirname(__DIR__) . '/vendor/autoload.php';

SessionUtils::sessionStart();
$user = SessionUtils::getUser();
?>
<!DOCTYPE HTML>
<html lang="id">
<head>
  <meta charset="utf-8"/>
  <title>Pindahkan Palet</title>
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
      let form_selectLocations;

      // noinspection JSAnnotator
      const LOCATION_ID_REGEX = <?= PlantIdHelper::locationRegex() ?>;

      // flags for the form inputs.
      let gridLoaded = false;
      let currentLocationIdValid = false;
      let newLocationIdValid = false;

      // auto mode: every pallet is automatically checked (marked for move)
      let form_auto = false;

      // TODO make something better than a 'mutex' for handling the 'flow' of application.
      let getCurrentLocationInProgress = false;
      let getNewLocationInProgress = false;
      let movePalletsInProgress = false;

      const palletsToMove = [];
      const currentLocation = {
        subplant: '',
        areaNo: '',
        areaName: '',
        lineNo: -1,
        <?php if (PlantIdHelper::usesLocationCell()): ?>
        cellNo: -1
        <?php endif ?>
      };

      /**
       * Checks if the form is in auto mode.
       * If so, do the move directly.
       **/
      function autoMovePallets() {
        if (form_auto && canMovePallets()) {
          const newLocationId = form_selectLocations.getItemValue('new_location_id');
          movePallets(palletsToMove, newLocationId);
        }
      }

      /**
       * Handles error by displaying proper error message (using dhtmlx)
       * @param {Error|string} error the error to be displayed, either as HTML or plain text.
       * @param {function} callback function to be called after the error message has been displayed (e.g. cleanups/undo operations).
       */
      function handleError(error, callback = function () {
      }) {
        if (form_auto) {
          dhtmlx.message({
            text: error,
            expire: DEFAULT_MESSAGE_BOX_EXPIRE_MS
          });
          callback();
        } else {
          dhtmlx.alert({
            type: "alert-warning",
            text: error,
            title: 'Error',
            callback: callback
          })
        }
      }

      /**
       * Check whether the form is valid for moving pallets.
       * @return boolean
       */
      function canMovePallets() {
        return currentLocationIdValid && newLocationIdValid && palletsToMove.length > 0;
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

      function doOnLoad() {
        layout_root = new dhtmlXLayoutObject({
          parent: document.body,
          pattern: '2E',
          cells: [
            {id: CELL_LOCATION_FORM, text: 'Lokasi Lama dan Lokasi Baru', height: 200},
            {id: CELL_PALLETS_PER_LOCATION_GRID, text: 'Daftar Palet'}
          ]
        });

        // setup selectLocations part
        form_selectLocations = layout_root.cells(CELL_LOCATION_FORM).attachForm([
          {type: "settings", position: "label-left", labelWidth: 150, inputWidth: 160},
          {
            type: "block", inputWidth: "auto", offsetTop: 0, width: 400, blockOffset: 0, list: [
              {
                type: "checkbox",
                name: "mode_auto",
                label: "Otomatis"
              },
              {
                type: 'input',
                name: 'current_location_id',
                label: 'Kd. Lok. Awal',
                maxLength: 8,
                style: "text-transform: uppercase; font-size: 24px !important;"
              },
              {
                type: 'input',
                name: 'new_location_id',
                label: 'Kd. Lok. Baru',
                maxLength: 8,
                style: "text-transform: uppercase; font-size: 24px !important;"
              },
            ]
          },
          {type: 'newcolumn'},
          {type: 'button', name: 'move_pallets', value: 'Pindahkan Palet', disabled: true}
        ]);
        form_selectLocations.setFontSize('16px');
        form_selectLocations.attachEvent('onChange', function (name, value, state) {
          if (name === 'mode_auto') {
            form_auto = state;
          }
        });
        form_selectLocations.attachEvent('onButtonClick', buttonId => {
          if (buttonId === 'move_pallets') {
            const newLocationId = form_selectLocations.getItemValue('new_location_id');
            dhtmlx.confirm({
              title: 'Konfirmasi Pindah Palet',
              text: `Apakah Anda yakin ingin memindahkan ${palletsToMove.length} palet ke ${newLocationId}?`,
              // isConfirmed is a boolean, which is true (OK)/false (Cancel) based on the selected response in the modal box.
              // refer back to DHTMLX docs about callbacks in message boxes.
              callback: isConfirmed => {
                if (isConfirmed) {
                  movePallets(palletsToMove, newLocationId);
                }
              }
            })
          }
        });

        form_selectLocations.attachEvent('onKeyup', _.debounce((inp, ev, id, value) => {
          if (id === 'current_location_id' || id === 'new_location_id') {
            form_selectLocations.setItemValue(id, form_selectLocations.getItemValue(id).toUpperCase());
          }

          if (id === 'current_location_id') {
            currentLocationIdValid = false;
            if (gridLoaded) {
              clearPalletsGrid();
              form_selectLocations.disableItem('move_pallets');
              palletsToMove.splice(0, palletsToMove.length);
            }

            // do validation
            const currentLocationId = form_selectLocations.getItemValue(id);
            if (LOCATION_ID_REGEX.test(currentLocationId)) {
              if (getCurrentLocationInProgress) {
                return;
              }

              getCurrentLocationInProgress = true;
              getLocationInfo(currentLocationId)
                .then(location => {
                  currentLocation.subplant = location.plant_code;
                  currentLocation.areaNo = location.area_code;
                  currentLocation.areaName = location.area_name;
                  currentLocation.lineNo = location.line_no;
                  <?php if (PlantIdHelper::usesLocationCell()): ?>
                  currentLocation.cellNo = location.cell_no;
                  <?php endif ?>

                  // get the pallets, since the location has been verified
                  fetchPallets(currentLocationId)
                    .then(() => {
                      currentLocationIdValid = true;
                      // properly enable the forms
                      if (canMovePallets()) {
                        form_selectLocations.enableItem('move_pallets');
                      } else {
                        form_selectLocations.setItemFocus('new_location_id');
                      }

                      // auto check everything.
                      if (form_auto) {
                        grid_palletsByLocation.setCheckedRows(grid_palletsByLocation.getColIndexById('is_checked'), 1);
                        grid_palletsByLocation.forEachRow(rowId => {
                          palletsToMove.push(rowId);
                        })
                      }
                    })
                    .then(() => {
                      getCurrentLocationInProgress = false;
                    })
                    .then(autoMovePallets)
                    .catch(error => {
                      handleError(error, () => {
                        getCurrentLocationInProgress = false;
                        clearLocationId(id);
                      })
                    })
                })
                .catch(error => {
                  handleError(error, () => {
                    clearLocationId(id);
                  });
                })
            }
          } else if (id === 'new_location_id') {
            form_selectLocations.disableItem('move_pallets');
            newLocationIdValid = false;
            // do validation
            const newLocationId = form_selectLocations.getItemValue(id);
            if (LOCATION_ID_REGEX.test(newLocationId)) {
              if (getNewLocationInProgress) {
                return;
              }

              getNewLocationInProgress = true;
              getLocationInfo(newLocationId)
                .then(location => {
                  newLocationIdValid = true;

                  if (canMovePallets()) {
                    form_selectLocations.enableItem('move_pallets');
                  } else {
                    form_selectLocations.setItemFocus('current_location_id');
                  }
                })
                .then(() => {
                  getNewLocationInProgress = false;
                })
                .then(autoMovePallets)
                .catch(error => {
                  handleError(error, () => {
                    getNewLocationInProgress = false;
                    clearLocationId(id);
                  });
                });
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
          const currentLocationId = form_selectLocations.getItemValue('current_location_id');
          let title = `Daftar Palet di ${currentLocationId}`;

          const pdfWidths = '30,120,80,45,60,*,75,30,40,30,60,40,120';
          switch (itemId) {
            case 'refresh':
              fetchPallets(currentLocationId);
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
            if (!palletsToMove.includes(rowId)) {
              palletsToMove.push(rowId);
            }
          } else {
            // rowId = palletNo
            const idx = palletsToMove.indexOf(rowId);
            if (idx > -1) {
              palletsToMove.splice(idx, 1);
            }
          }

          if (canMovePallets()) {
            form_selectLocations.enableItem('move_pallets');
          } else {
            form_selectLocations.disableItem('move_pallets');
          }
        });

        grid_palletsByLocation.enableSmartRendering(true, 100);
        grid_palletsByLocation.init();
      }

      /**
       * Clears out the form (and the grid) of the screen.
       * @param {string} id id of the form to remove. Either 'current_location_id' or 'new_location_id'
       **/
      function clearLocationId(id) {
        form_selectLocations.setItemValue(id, '');
        if (id === 'current_location_id') {
          currentLocationIdValid = false;
          clearPalletsGrid();
        } else if (id === 'new_location_id') {
          newLocationIdValid = false;
        }
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
            cell.progressOff();
            cell.setText(`Daftar Palet (${locationId})`);

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
       * Moves pallets to a new location.
       * @param {Array} palletNos
       * @param {string} newLocationId
       */
      function movePallets(palletNos, newLocationId) {
        if (movePalletsInProgress) {
          return;
        }

        movePalletsInProgress = true;
        layout_root.cells(CELL_LOCATION_FORM).progressOn();
        WMSApi.location.addPalletsToLocation(palletNos, newLocationId)
          .then(response => { // display message
            layout_root.cells(CELL_LOCATION_FORM).progressOff();
            movePalletsInProgress = false;

            dhtmlx.message({
              text: response.msg,
              expire: DEFAULT_MESSAGE_BOX_EXPIRE_MS
            });
          })
          .then(() => { // clear form and start from beginning again.
            clearLocationId('current_location_id');
            clearLocationId('new_location_id');
            palletsToMove.splice(0, palletsToMove.length);

            form_selectLocations.disableItem('move_pallets');

            form_selectLocations.setItemFocus('current_location_id');
          })
          .catch(error => {
            layout_root.cells(CELL_LOCATION_FORM).progressOff();
            handleError(error, () => {
              movePalletsInProgress = false;
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
