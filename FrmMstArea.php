<?php
require_once __DIR__ . '/vendor/autoload.php';

use Security\RoleAcl;

SessionUtils::sessionStart();

$errorMessage = null;
if (!SessionUtils::isAuthenticated()) {
  // print error
  http_response_code(HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
  $errorMessage = 'You are not authenticated!';
  die($errorMessage);
}

if (isset($errorMessage)) {
  die ($errorMessage);
}

$user = SessionUtils::getUser();
?>
<!DOCTYPE HTML>
<html lang="id">
<head>
  <meta charset="utf-8"/>
  <title>Master Area Palet</title>
  <link rel="stylesheet" type="text/css" href="assets/libs/dhtmlx/dhtmlx.css"/>
  <link rel="stylesheet" type="text/css" href="assets/fonts/font_awesome/css/font-awesome.min.css"/>
  <link rel="stylesheet" type="text/css" href="assets/fonts/font_roboto/roboto.css"/>

  <script src="assets/libs/dhtmlx/dhtmlx.js"></script>
  <script src="assets/libs/axios/axios.min.js"></script>
  <script src="assets/libs/pdfmake/pdfmake.min.js"></script>
  <script src="assets/libs/pdfmake/vfs_fonts.js"></script>
  <script src="assets/libs/js-cookie/js.cookie.min.js"></script>
  <script src="assets/libs/JsBarcode/JsBarcode.all.min.js"></script>

  <script src="assets/js/date-utils.js"></script>
  <script src="assets/js/WMSApi-20190723-02.js"></script>
  <script src="assets/js/grid-custom-types-20190704-01.js"></script>
  <script src="assets/js/grid-utils-20190704-01.js"></script>
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
  </style>
  <script>
    const masterArea = (function (axios, pdfMake, JsBarcode, gridUtils, WMSApi) {
      'use strict';

      const TEXT_RIGHT_ALIGN = gridUtils.styles.TEXT_RIGHT_ALIGN;
      const TEXT_BOLD = gridUtils.styles.TEXT_BOLD;

      const USERID = '<?= $user->gua_kode ?>';
      const SUBPLANTS_OPTIONS = [];

      const HEADER_TEXT_FILTER = gridUtils.headerFilters.TEXT;
      const HEADER_NUMERIC_FILTER = gridUtils.headerFilters.NUMERIC;

      WMSApi.auth.fetchAllAllowedSubplants()
        .then(subplants => {
          subplants.forEach(subplant => {
            SUBPLANTS_OPTIONS.push({
              text: subplant,
              value: subplant
            });
          });
        });

      let rootGrid, rootLayout, rootToolbar;
      const frm = "FrmMstAreaProc.php";

      function refresh() {
        axios.get(frm + "?mode=view")
          .then(response => {
            const result = response.data.data;
            rootGrid.clearAll();
            rootGrid.parse(result, 'js');
          });
      }

      let windows;
      function doOnLoad() {
        rootLayout = new dhtmlXLayoutObject(document.body, "1C");
        windows = new dhtmlXWindows();
        rootLayout.cells("a").hideHeader();

        const toolbarItems = [
          {type: 'button', id: 'new', text: 'Tambah Area', img: 'fa fa-plus', imgdis: 'fa fa-plus', enabled: false},
          {type: 'separator'},
          {type: 'button', id: 'edit', text: 'Ubah Area', img: 'fa fa-edit', imgdis: 'fa fa-edit', enabled: false},
          {type: 'separator'},
          {type: 'button', id: 'print', text: 'Cetak', img: 'fa fa-print', imgdis: 'fa fa-print'},
          {type: 'separator'},
          {type: 'button', id: 'refresh', text: 'Segarkan', img: 'fa fa-refresh', imgdis: 'fa fa-refresh'}
        ];

        rootToolbar = rootLayout.attachToolbar({
          iconset: 'awesome',
          items: toolbarItems
        });
        rootToolbar.attachEvent("onClick", function (itemId) {
          switch (itemId) {
            case 'new':
              openAreaForm();
              break;
            case 'edit':
              openAreaForm(rootGrid.getSelectedRowId());
              break;
            case 'print':
              const title = 'Daftar Area Palet';
              rootLayout.cells('a').progressOn();
              gridUtils.generateFilteredPdf(rootGrid, title, USERID).getBlob(blob => {
                rootLayout.cells('a').progressOff();
                gridUtils.openPDFWindow(title, title, blob, null, windows);
              });
              break;
            case 'refresh':
              refresh();
              break;
          }
        });

        rootGrid = rootLayout.cells("a").attachGrid();
        rootGrid.setHeader('NO.,SUBP.,KD AREA,NAMA,TTL. BRS.,CATATAN,AKTIF,TERAKHIR DIUBAH,PENGUBAH', null, ['', '', '', '', TEXT_RIGHT_ALIGN, '', '', '', '', '']);
        rootGrid.setColumnIds('no,subplant,area_code,area_name,row_count,remarks,area_status,updated_at,updated_by');

        // check if the user is allowed to do editing on the master data.
        let ALLOW_EDIT = <?= UserRole::hasAnyRole(RoleAcl::masterAreaModification()) ? 'true' : 'false' ?>;
        if (ALLOW_EDIT) {
            rootToolbar.enableItem('new');
            if (rootGrid.getSelectedRowId() !== null) {
                rootToolbar.enableItem('edit');
            }
        }
        rootGrid.attachEvent('onRowSelect', () => {
          rootToolbar.forEachItem(itemId => {
            if (!rootToolbar.isEnabled(itemId) && ALLOW_EDIT) {
              rootToolbar.enableItem(itemId);
            }
          })
        });

        rootGrid.setColTypes("ron,rotxt,rotxt,rotxt,ron,rotxt,rotxt,ro_ts,rotxt");
        rootGrid.setInitWidths("40,60,80,200,80,*,60,150,80");
        rootGrid.setColAlign("right,left,left,left,right,left,left,left,left");
        rootGrid.setColSorting("int,str,str,str,int,na,str,str,str");
        rootGrid.init();
        rootGrid.attachEvent('onRowDblClicked', rowId => {
          const subplant = rootGrid.cells(rowId, rootGrid.getColIndexById('subplant')).getValue();
          const areaCode = rootGrid.cells(rowId, rootGrid.getColIndexById('area_code')).getValue();
          const areaName = rootGrid.cells(rowId, rootGrid.getColIndexById('area_name')).getValue();
          openLineForm(subplant, areaCode, areaName);
        });

        refresh();
      }

      function openAreaForm(rowId = null) {
        const win = windows.createWindow("w1", 0, 0, 700, 300);
        win.centerOnScreen();
        win.setText("Master Area Pallet");
        win.button("park").hide();
        win.setModal(true);

        // fetch input field from row
        let subplant = '', areaCode = '', areaName = '', rowCount = 0, remarks = '', status = '';
        if (rowId !== null) {
          subplant = rootGrid.cells(rowId, rootGrid.getColIndexById('subplant')).getValue();
          areaCode = rootGrid.cells(rowId, rootGrid.getColIndexById('area_code')).getValue();
          areaName = rootGrid.cells(rowId, rootGrid.getColIndexById('area_name')).getValue();
          rowCount = rootGrid.cells(rowId, rootGrid.getColIndexById('row_count')).getValue();
          remarks = rootGrid.cells(rowId, rootGrid.getColIndexById('remarks')).getValue();
          status = rootGrid.cells(rowId, rootGrid.getColIndexById('area_status')).getValue();
        }

        const minRowCount = rowCount === 0 ? 1 : rowCount;
        const formStructure = [
          {type: "settings", position: "label-left", labelAlign: "right", labelWidth: 130, inputWidth: 230},
          {
            type: "block", width: 600, offsetLeft: 10, offsetTop: 10, list: [
              {
                type: "fieldset", name: "master-area", label: "Input Master Area", width: 600, list: [
                  {
                    type: "combo",
                    offsetLeft: 0,
                    name: "subplant",
                    label: "Subplant",
                    inputWidth: 50,
                    readonly: rowId !== null,
                    required: true,
                    options: SUBPLANTS_OPTIONS,
                    value: subplant
                  },
                  {
                    type: "input",
                    name: "area_code",
                    label: "Kode Area",
                    inputWidth: 100,
                    maxLength: 3,
                    readonly: rowId !== null,
                    required: true,
                    style: "text-transform: uppercase;",
                    validate: (input) => {
                      const length = String(input).length;
                      return length === 3;
                    },
                    value: areaCode
                  },
                  {
                    type: "input",
                    name: "area_name",
                    label: "Nama Area",
                    inputWidth: 400,
                    required: true,
                    style: "text-transform: uppercase;",
                    value: areaName
                  },
                  {
                    type: "input",
                    name: "row_count",
                    label: "Jumlah Baris",
                    inputWidth: 100,
                    maxLength: 3,
                    required: true,
                    style: "text-transform: uppercase;",
                    validate: input => !Number.isNaN(Number.parseInt(input)) && input >= minRowCount && input <= 999,
                    value: rowCount
                  },
                  {
                    type: "combo",
                    offsetLeft: 0,
                    name: "status",
                    label: "AKTIF",
                    inputWidth: 50,
                    readonly: rowId !== null,
                    required: true,
                    options: [
                      {value: "Ya", text: "Ya"},
                      {value: "Tidak", text: "Tidak"}
                    ]
                  },
                  {
                    type: "input",
                    name: "remarks",
                    label: "Catatan",
                    inputWidth: 400,
                    required: false,
                    rows: 2,
                    value: remarks
                  },
                  {type: 'hidden', name: 'method', value: rowId === null ? 'create' : 'edit'},
                  {
                    type: "block", offsetTop: 0, offsetLeft: 110, list: [
                      {type: "button", name: "save", value: "Simpan", style: "width: 200px;"},
                      {type: "newcolumn"},
                      {type: "button", name: "cancel", value: "Batal"}
                    ]
                  }
                ]
              }
            ]
          }
        ];

        // setup form
        const areaForm = win.attachForm(formStructure);
        if (rowId !== null) {
          areaForm.setItemValue('status', status);
        }

        areaForm.enableLiveValidation(true);
        areaForm.setFocusOnFirstActive();
        areaForm.attachEvent('onKeyUp', (input, event, id) => {
          if (id === 'area_code' || id === 'area_name') {
            areaForm.setItemValue(id, areaForm.getItemValue(id).toUpperCase())
          }
        });
        areaForm.attachEvent('onButtonClick', buttonId => {
          if (buttonId === 'save') {
            if (!areaForm.validate()) {
              dhtmlx.alert({
                title: 'Masukan Tidak Valid!',
                type: "alert-warning",
                text: 'Mohon periksa kembali data yang akan masukkan.'
              });
              return;
            }

            areaForm.send(frm + '?mode=save', 'post', (request, response) => {
              if (request.xmlDoc.status !== 200) {
                const json = JSON.parse(request.xmlDoc.response);
                dhtmlx.alert({
                  text: json.msg
                });
              } else {
                const json = JSON.parse(response);
                const newData = json.data;

                if (rowId === null) {
                  rootGrid.addRow(newData.id, '');
                  newData.no = rootGrid.getRowsNum();
                } else {
                  newData.no = rootGrid.getRowIndex(newData.id);
                }
                rootGrid.forEachCell(newData.id, (cell, index) => {
                  const columnId = rootGrid.getColumnId(index);
                  cell.setValue(newData[columnId]);
                });

                win.close();
                dhtmlx.message({
                  text: 'Data berhasil dimasukkan!'
                })
              }
            });
          } else if (buttonId === 'cancel') {
            dhtmlx.confirm({
              type: 'confirm',
              text: 'Yakin ingin membatalkan masukan?',
              title: 'Konfirmasi Batal Memasukkan Data',
              ok: 'Ya',
              no: 'Tidak',
              callback: result => {
                if (result) win.close()
              }
            })
          }
        })
      }

      function openLineForm(subplant, areaCode, areaName) {
        const winTitle = `Area ${areaName} (${areaCode}) - Subplant ${subplant}`;
        const windows = new dhtmlXWindows();
        const win = windows.createWindow("w1", 0, 0, 700, 450);
        win.centerOnScreen();
        win.setText(winTitle);
        win.button("park").hide();
        win.setModal(true);

        const toolbarItems = [
          {type: 'button', id: 'clear_filters', text: 'Bersihkan Penyaring Data', img: 'fa fa-close'},
          {type: 'separator'},
          {type: 'button', id: 'refresh', text: 'Segarkan', img: 'fa fa-refresh', imgdis: 'fa fa-refresh'},
          {type: 'separator'},
          {
            type: 'buttonSelect', id: 'print', text: 'Cetak', img: 'fa fa-print', imgdis: 'fa fa-print', options: [
              {
                type: 'button',
                id: 'print-barcode-selected',
                text: 'Cetak Satu Barcode',
                img: 'fa fa-print',
                imgdis: 'fa fa-print',
                enabled: false
              },
              {
                type: 'button',
                id: 'print-barcode-filtered',
                text: 'Cetak Semua Barcode',
                img: 'fa fa-print',
                imgdis: 'fa fa-print'
              }
            ]
          },
          {type: 'spacer'},
          {type: 'text', id: 'timestamp', text: ''},
        ];
        const winToolbar = win.attachToolbar({
          iconset: 'awesome',
          items: toolbarItems
        });

        const winGrid = win.attachGrid();
        winGrid.setHeader('SUBP.,KD AREA,NO. BRS.,KD. BRS.,TTL. PLT.,TTL. QTY', null,
          ['', '', TEXT_RIGHT_ALIGN, '', TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN]
        );
        winGrid.attachHeader(['#rspan', '#rspan', HEADER_NUMERIC_FILTER, HEADER_TEXT_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER]);

        winGrid.setColumnIds('subplant,area_code,row_no,location_id,pallet_count,current_quantity_sum');
        winGrid.setColSorting("str,str,int,str,int,int");
        winGrid.setColTypes("ro,ro,ron,ro,ron,ron");
        winGrid.setInitWidths("40,60,80,80,*,*");

        winGrid.setColAlign("left,left,right,right,right,right");
        winGrid.attachFooter(['', '', 'Total', '#cspan', '#stat_total', '#stat_total'],
          ['', '', TEXT_RIGHT_ALIGN + TEXT_BOLD, '', TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD]);
        winGrid.setColumnHidden(winGrid.getColIndexById('subplant'), true); // no need to show the subplant
        winGrid.setColumnHidden(winGrid.getColIndexById('area_code'), true); // no need to show the area code
        winGrid.setNumberFormat("0,000", winGrid.getColIndexById('pallet_count'), ",", ".");
        winGrid.setNumberFormat("0,000", winGrid.getColIndexById('current_quantity_sum'), ",", ".");
        winGrid.enableSmartRendering(true, 50);
        winGrid.init();
        winGrid.attachEvent('onRowSelect', () => {
          if (!winToolbar.isListOptionEnabled('print', 'print-barcode-selected')) {
            winToolbar.enableListOption('print', 'print-barcode-selected');
          }
        });

        winToolbar.attachEvent('onClick', itemId => {
          const currentDate = gridUtils.date.getCurrentLocaleDate(gridUtils.date.DEFAULT_LOCALE, gridUtils.date.LOCALE_ISO_DATE_OPTIONS);
          const filename = `${currentDate} - ${winTitle}`;
          switch (itemId) {
            case 'clear_filters':
              gridUtils.clearAllGridFilters(winGrid);
              break;
            case 'edit':
              openAreaForm(rootGrid.getSelectedRowId());
              break;
            case 'print':
              gridUtils.generateFilteredPdf(winGrid, winTitle, USERID)
                .getBlob(blob => {
                  gridUtils.openPDFWindow(winTitle, filename, blob, win, windows)
                }, {autoPrint: true});
              break;
            case 'print-barcode-selected':
              const selectedLocationId = winGrid.getSelectedRowId();
              win.progressOn();
              generateAreaBarcode([selectedLocationId], areaName)
                .getBlob(blob => {
                  win.progressOff();
                  gridUtils.openPDFWindow(winTitle, filename, blob, win, windows)
                });
              break;
            case 'print-barcode-filtered':
              win.progressOn();
              const visibleRowCount = winGrid.getRowsNum();
              const locationIds = [];

              for (let i = 0; i < visibleRowCount; i++) {
                const locationId = winGrid.getRowId(i);
                locationIds.push(locationId);
              }
              generateAreaBarcode(locationIds, areaName)
                .getBlob(blob => {
                  win.progressOff();
                  gridUtils.openPDFWindow(winTitle, filename, blob, win, windows)
                });
              break;
            case 'refresh':
              getRowsWithPalletInfo(subplant, areaCode);
              break;
          }
        });

        getRowsWithPalletInfo(subplant, areaCode);

        function getRowsWithPalletInfo(subplant, areaCode) {
          win.progressOn();
          return axios.get(`${frm}?mode=get_rows&subplant=${subplant}&area_code=${areaCode}`)
            .then(response => response.data.data)
            .then(rows => ({
              data: rows.map(row => Object.assign(row, {
                id: row.location_id
              }))
            }))
            .then(data => {
              win.progressOff();
              const now = gridUtils.date.getCurrentLocaleDate();
              winToolbar.setItemText('timestamp', now);

              winGrid.clearAll();
              winGrid.parse(data.data, 'js')
            })
            .catch(error => {
              win.progressOff();
              dhtmlx.alert({
                title: 'Error',
                type: 'alert-error',
                text: !(error instanceof Object) ? error :
                  error.hasOwnProperty('response') ? error.response : error.message
              });
            })
        }
      }

      function textToBase64Barcode(text) {
        const canvas = document.createElement('canvas');
        JsBarcode(canvas, text, {
          format: 'CODE39',
          width: 50,
          height: 400,
          displayValue: false
        });
        return canvas.toDataURL("image/png");
      }

      /**
       * Generates location barcode for an area.
       *
       * @param {array} text array of locationIds to be generated to barcode.
       * @param {string} areaName name of the area, that will be put to the barcode.
       * @returns {Document}
       */
      function generateAreaBarcode(text, areaName) {
        if (text.length === 0) {
          throw new Error('nothing to generate!');
        }

        const content = [];

        function TableTemplate(body, hasPageBreak = false) {
          this.style = 'table';
          this.table = {
            widths: ['auto', '*'],
            body: body
          };

          if (hasPageBreak) {
            this.pageBreak = 'after'
          }

          this.toObject = function () {
            const obj = {
              style: this.style,
              table: this.table
            };

            if (this.pageBreak) {
              obj.pageBreak = 'after'
            }
            return obj;
          }.bind(this);
        }

        for (let i = 0; i < text.length; i++) {
          const subplantCode = text[i].substr(0, 2);
          const areaCode = text[i].substr(2, 3);
          const lineNo = text[i].substr(5);

          const tableBody = [
            [ // header: subplant and area
              {text: `${subplantCode}-${areaCode}`, style: 'header'},
              {text: areaName, style: 'header'}
            ],
            [ // center: the barcode
              {
                colSpan: 2,
                image: textToBase64Barcode(text[i]),
                width: 700,
                height: 250,
                alignment: 'center',
                layout: {
                  paddingLeft: function (i, node) {
                    return 10;
                  },
                  paddingTop: function (i, node) {
                    return 20;
                  },
                  paddingRight: function (i, node) {
                    return 2;
                  },
                  paddingBottom: function (i, node) {
                    return 20;
                  }
                }
              },
              {}
            ],
            [ // footer: lineNo and locationId.
              {text: `BRS. ${lineNo}`, style: 'header', noWrap: true},
              {text: text[i], style: 'header'}
            ]
          ];

          content.push((new TableTemplate(tableBody, i < (text.length - 1))).toObject());
        }

        const dd = {
          info: {
            title: `Barcode Area ${areaName}`
          },
          pageSize: 'A4',
          pageOrientation: 'landscape',
          pageMargins: [40, 50],
          content: content,
          styles: {
            header: {
              fontSize: 60,
              bold: true,
              alignment: 'center',
              verticalAlignment: 'center'
            },
            table: {
              margin: [0, 5, 0, 15]
            }
          },
          footer: (currentPage, pageCount) => ({
            columns: [
              `Dibuat oleh [${USERID}] pada ${gridUtils.date.getCurrentLocaleDateTime()}`,
              {text: `Halaman ${currentPage} dari ${pageCount}`, alignment: 'right'}
            ],
            margin: [40, 30, 40, 50],
            fontSize: 10
          })
        };

        return pdfMake.createPdf(dd);
      }

      return {doOnLoad};
    })(axios, pdfMake, JsBarcode, gridUtils, WMSApi);
  </script>
</head>
<body onload="masterArea.doOnLoad()">

</body>
</html>
