<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Security\RoleAcl;

SessionUtils::sessionStart();

$user = SessionUtils::getUser();

// check authorization
$authorized = !empty($user->gua_subplant_handover);
if ($authorized) {
  $allowedRoles = RoleAcl::downgradePalletsReasonModification();
  $authorized = UserRole::hasAnyRole($allowedRoles);
}

if (!$authorized) {
  http_response_code(HttpUtils::HTTP_RESPONSE_FORBIDDEN);
  $errorMessage = 'You are not allowed to access downgrade master!';
}
?>

<!DOCTYPE HTML>
<html lang="id">
<head>
  <meta charset="utf-8"/>
  <title>Master Keterangan Downgrade</title>
  <link rel="stylesheet" type="text/css" href="../assets/libs/dhtmlx/dhtmlx.css"/>
  <link rel="stylesheet" type="text/css" href="../assets/fonts/font_awesome/css/font-awesome.min.css"/>
  <link rel="stylesheet" type="text/css" href="../assets/fonts/font_roboto/roboto.css"/>

  <script src="../assets/libs/dhtmlx/dhtmlx.js"></script>
  <script src="../assets/libs/axios/axios.min.js"></script>
  <script src="../assets/libs/pdfmake/pdfmake.min.js"></script>
  <script src="../assets/libs/pdfmake/vfs_fonts.js"></script>
  <script src="../assets/libs/js-cookie/js.cookie.min.js"></script>
  <script src="../assets/libs/JsBarcode/JsBarcode.all.min.js"></script>

  <script src="../assets/js/date-utils.js"></script>
  <script src="../assets/js/WMSApi-20190723-02.js"></script>
  <script src="../assets/js/grid-custom-types-20190704-01.js"></script>
  <script src="../assets/js/grid-utils-20190704-01.js"></script>
  <script src="../assets/js/error-handler-20190723-02.js"></script>
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
    const masterDowngradeReasons = (function (axios, gridUtils, WMSApi, handleApiError) {
      'use strict';
      WMSApi.setBaseUrl('../api');

      const TEXT_RIGHT_ALIGN = gridUtils.styles.TEXT_RIGHT_ALIGN;

      let rootLayout;
      let windows;
      function doOnLoad() {
        rootLayout = new dhtmlXLayoutObject(document.body, "1C");
        windows = new dhtmlXWindows();
        rootLayout.cells("a").hideHeader();

        const toolbarItems = [
          {type: 'button', id: 'new', text: 'Tambah Keterangan', img: 'fa fa-plus', imgdis: 'fa fa-plus'},
          {type: 'separator'},
          {type: 'button', id: 'enable', text: 'Aktifkan', img: 'fa fa-check', imgdis: 'fa fa-check', disabled: true},
          {type: 'button', id: 'disable', text: 'Nonaktifkan', img: 'fa fa-close', imgdis: 'fa fa-close', disabled: true},
          {type: 'separator'},
          {type: 'button', id: 'refresh', text: 'Segarkan', img: 'fa fa-refresh', imgdis: 'fa fa-refresh'}
        ];

        const rootToolbar = rootLayout.cells('a').attachToolbar({
          iconset: 'awesome',
          items: toolbarItems
        });
        rootToolbar.attachEvent("onClick", function (itemId) {
          switch (itemId) {
            case 'new':
              openCreateNewReasonForm();
              break;
            case 'enable': {
              const selectedReasonId = rootGrid.getSelectedRowId();
              if (!selectedReasonId) return;
              const reason = rootGrid.cells(selectedReasonId, rootGrid.getColIndexById('reason')).getValue();
              confirmSetReasonDisabled(selectedReasonId, reason, false);
              break;
            }
            case 'disable': {
              const selectedReasonId = rootGrid.getSelectedRowId();
              if (!selectedReasonId) return;
              const reason = rootGrid.cells(selectedReasonId, rootGrid.getColIndexById('reason')).getValue();
              confirmSetReasonDisabled(selectedReasonId, reason, true);
              break;
            }
            case 'refresh':
              refresh();
              break;
          }
        });

        const rootGrid = rootLayout.cells("a").attachGrid();
        rootGrid.setHeader('NO.,ID,KETERANGAN DWG.,NONAKTIF,SEJAK,TERAKHIR DIUBAH', null, [TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN]);
        rootGrid.setColumnIds('no,id,reason,is_disabled,created_at,updated_at,updated_by');
        rootGrid.setColTypes('cntr,ron,rotxt,ro_bool,ro_ts,ro_ts,rotxt');
        rootGrid.setInitWidths('40,0,*,80,160,160');
        rootGrid.setColAlign('right,right,left,left,left,left');
        rootGrid.setColSorting('na,na,str,str,str,str');
        rootGrid.setColumnHidden(rootGrid.getColIndexById('id'), true);
        rootGrid.init();

        rootGrid.attachEvent('onRowSelect', rowId => {
          const val = rootGrid.cells(rowId, rootGrid.getColIndexById('is_disabled')).getValue();
          const isDisabled = val === 'Ya'; // TODO refactor?
          if (isDisabled) {
            rootToolbar.enableItem('enable');
            rootToolbar.disableItem('disable');
          } else {
            rootToolbar.disableItem('enable');
            rootToolbar.enableItem('disable');
          }
        });
        refresh();
      }

      function openCreateNewReasonForm() {
        const win = windows.createWindow("w1", 0, 0, 450, 200);
        win.centerOnScreen();
        win.setText('Buat Alasan Downgrade Baru');
        win.button('park').hide();
        win.setModal(true);

        const formStructure = [
          {type: "settings", position: "label-left", labelAlign: "right", labelWidth: 130, inputWidth: 230},
          {
            type: "block", width: 400, offsetLeft: 10, offsetTop: 10, list: [
              {
                type: "fieldset", name: "master-area", label: 'Alasan Downgrade', width: 300, list: [
                  {
                    type: 'input',
                    name: 'reason',
                    inputWidth: 300,
                    maxLength: 100,
                    required: true,
                    style: "text-transform: uppercase;",
                    validate: input => {
                      const length = String(input).length;
                      return length >= 3;
                    }
                  },
                  {
                    type: "block", offsetTop: 0, offsetLeft: 60, list: [
                      {type: "button", name: "save", value: "Simpan", style: "width: 200px;", disabled: true},
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
        const downgradeReasonForm = win.attachForm(formStructure);

        downgradeReasonForm.enableLiveValidation(true);
        downgradeReasonForm.setFocusOnFirstActive();
        downgradeReasonForm.attachEvent('onKeyUp', (input, event, id) => {
          if (id === 'reason') {
            const reason = downgradeReasonForm.getItemValue(id);
            downgradeReasonForm.setItemValue(id, reason.toUpperCase());
            downgradeReasonForm.validate();
          }
        });
        downgradeReasonForm.attachEvent('onAfterValidate', isValid => {
          console.debug(isValid);
          if (isValid) {
            downgradeReasonForm.enableItem('save');
          } else {
            downgradeReasonForm.disableItem('save');
          }
        });
        downgradeReasonForm.attachEvent('onButtonClick', buttonId => {
          if (buttonId === 'save') {
            const reason = downgradeReasonForm.getItemValue('reason');
            win.progressOn();
            WMSApi.qa.createNewDowngradeReason(reason)
              .then(result => {
                win.progressOff();
                const rootGrid = rootLayout.cells('a').getAttachedObject();
                rootGrid.addRow(result.id, '');
                rootGrid.setRowData(result.id, result);
                rootGrid.callEvent('onGridReconstructed', []);
                win.close();
              })
              .catch(error => {
                win.progressOff();
                handleApiError(error);
              })
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

      function confirmSetReasonDisabled(id, reason, isDisabled) {
        dhtmlx.confirm({
          type: 'confirm',
          text: `Yakin ingin ${isDisabled ? 'menonaktifkan' : 'mengaktifkan'} alasan downgrade '${reason}'?`,
          title: 'Konfirmasi Perubahan Status Keterangan Downgrade',
          ok: 'Ya',
          no: 'Tidak',
          callback: result => {
            if (!result) {
              return;
            }

            const rootCell = rootLayout.cells('a');
            rootCell.progressOn();
            WMSApi.qa.updateExistingDowngradeReasonStatus(id, isDisabled)
              .then(result => {
                // set and reset grid
                const grid = rootCell.getAttachedObject();
                grid.setRowData(id, result);
                grid.clearSelection();

                const toolbar = rootCell.getAttachedToolbar();
                toolbar.disableItem('enable');
                toolbar.disableItem('disable');

                rootCell.progressOff();
              })
              .catch(error => {
                rootCell.progressOff();
                handleApiError(error);
              })
          }
        })
      }

      function refresh() {
        const rootCell = rootLayout.cells('a');
        WMSApi.qa.getAvailableDowngradeReasons()
          .then(downgradeReasons => {
            rootCell.progressOff();
            const rootGrid = rootCell.getAttachedObject();
            rootGrid.clearAll();
            rootGrid.parse(downgradeReasons, 'js');
          })
          .catch(error => {
            rootCell.progressOff();
            handleApiError(error);
          });
      }

      return {doOnLoad};
    })(axios, gridUtils, WMSApi, handleApiError);
  </script>
</head>
<body onload="masterDowngradeReasons.doOnLoad()">

</body>
</html>
