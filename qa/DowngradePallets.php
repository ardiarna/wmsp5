<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Model\PalletDowngrade;
use Security\RoleAcl;
use Utils\Env;

SessionUtils::sessionStart();

$user = SessionUtils::getUser();

// check authorization
$authorized = !empty($user->gua_subplant_handover);
if ($authorized) {
  $allowedRoles = RoleAcl::downgradePallets();
  $authorized = UserRole::hasAnyRole($allowedRoles);
}

if (!$authorized) {
  http_response_code(HttpUtils::HTTP_RESPONSE_UNAUTHORIZED);
  die('Anda tidak punya akses ke data downgrade palet!');
}

$downgradeReasons = array();
try {
  $db = PostgresqlDatabase::getInstance();
  $downgradeReasonsQuery = 'SELECT * FROM tbl_sp_ket_dg_pallet WHERE plan_kode = get_plant_code()::VARCHAR AND is_disabled IS FALSE ORDER BY id';
  $downgradeReasonsResult = $db->rawQuery($downgradeReasonsQuery);
  assert(pg_num_rows($downgradeReasonsResult) > 0);

  while ($row = pg_fetch_assoc($downgradeReasonsResult)) {
    $downgradeReasons[$row['id']] = $row['reason'];
  }
  $db->close();
} catch (PostgresqlDatabaseException $e) {
  http_response_code(HttpUtils::HTTP_RESPONSE_SERVER_ERROR);
  die('Gagal mendapatkan daftar alasan downgrade: ' . $e->getMessage() . ".\n" . (Env::isDebug() ? $e->getTraceAsString() : ''));
}
?>
<!DOCTYPE HTML>
<html lang="id">
<head>
  <meta charset="utf-8"/>
  <title>Downgrade Palet</title>
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
  <script src="../assets/js/WMSApi-20190723-02.js"></script>
  <script src="../assets/js/grid-custom-types-20190704-01.js"></script>
  <script src="../assets/js/grid-utils-20190704-01.js"></script>
  <script src="../assets/js/error-handler-20190723-02.js"></script>
  <script src="../assets/js/views/pallets-for-blocking.js"></script>
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
    // noinspection JSAnnotator
    const TYPE_OPTIONS = <?= json_encode(PalletDowngrade::availableTypes()) ?>;
    // noinspection JSAnnotator
    const STATUS_OPTIONS = <?= json_encode(PalletDowngrade::availableStatus()) ?>;
    // noinspection JSAnnotator
    const REASONS_OPTIONS = <?= json_encode($downgradeReasons) ?>;

    function eXcell_downgradeStatus(cell) { // the eXcell name is defined here
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
    eXcell_downgradeStatus.prototype = new eXcell_ro;// nests all other methods from the base class

    function eXcell_downgradeType(cell) { // the eXcell name is defined here
      this.base = eXcell_ro;
      this.base(cell);
      this.setValue = function (val) {
        // actual data processing may be placed here, for now we just set value as it is
        this.setCValue(`<span data-val="${val}">${TYPE_OPTIONS[val].label}</span>`);
      };

      this.getValue = function () {
        return this.cell.firstChild.dataset.val
      };
    }
    // noinspection JSPotentiallyInvalidConstructorUsage
    eXcell_downgradeType.prototype = new eXcell_ro;// nests all other methods from the base class

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
          {id: CELL_REQUEST_FILTER, header: true, text: 'Daftar Permintaan Downgrade Palet', height: 90},
          {id: CELL_REQUEST_LIST, header: false}
        ]
      });
      windows = new dhtmlXWindows();

      setupRequestFilters(rootLayout.cells(CELL_REQUEST_FILTER));
      setupRequestGrid(rootLayout.cells(CELL_REQUEST_LIST));
    }

    function setupRequestFilters(cell) {
      const ALL = {text: 'Semua', value: 'all', selected: true};

      // transform options
      const subplantOptions = [ALL].concat(SUBPLANTS_OPTIONS.map(subplant => ({text: subplant, value: subplant})));
      const typeOptions = [ALL].concat(Object.keys(TYPE_OPTIONS).map(val => ({text: TYPE_OPTIONS[val].label, value: val})));
      const statusOptions = [ALL].concat(Object.keys(STATUS_OPTIONS).map(val => ({
        text: STATUS_OPTIONS[val],
        value: val
      })));
      const reasonsOptions = [ALL].concat(Object.keys(REASONS_OPTIONS).map(reason => ({
        text: REASONS_OPTIONS[reason],
        value: REASONS_OPTIONS[reason]
      })));

      const filterFormConfig = [
        {type: "settings", position: "label-left", labelWidth: 50, inputWidth: 160},
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
        {
          type: 'calendar',
          offsetLeft: 20,
          name: 'from_date',
          label: 'Dari',
          enableTodayButton: true,
          required: true,
          dateFormat: "%Y-%m-%d",
          calendarPosition: "right",
          inputWidth: 100,
          value: '<?= date('Y-m-01') ?>' // first day of current month
        },
        {type: 'newcolumn'},
        {
          type: 'calendar',
          offsetLeft: 20,
          name: 'to_date',
          label: 'Hingga',
          enableTodayButton: true,
          required: true,
          readonly: true,
          dateFormat: "%Y-%m-%d",
          calendarPosition: "right",
          inputWidth: 100,
          value: '<?= date('Y-m-d') ?>' // first day of current month
        },
        {type: 'newcolumn'},
        {
          type: 'combo',
          name: 'status',
          label: 'Status',
          options: statusOptions,
          required: true,
          readonly: true,
          offsetLeft: 20,
          inputWidth: 80
        },
        {type: 'newcolumn'},
        {
          type: 'combo',
          name: 'type',
          label: 'Jenis',
          options: typeOptions,
          required: true,
          readonly: true,
          offsetLeft: 20,
          inputWidth: 80
        },
        {type: 'newcolumn'},
        {
          type: 'combo',
          name: 'reason',
          label: 'Keterangan',
          options: reasonsOptions,
          required: true,
          readonly: true,
          offsetLeft: 20,
          labelWidth: 80,
          inputWidth: 200
        },
        {type: 'newcolumn'},
        {type: 'button', offsetLeft: 20, name: 'getdata', value: 'Dapatkan Data!'}
      ];
      const filterForm = cell.attachForm(filterFormConfig);

      filterForm.attachEvent('onChange', (name, value) => {
        switch (name) {
          case 'from_date':
            filterForm.getCalendar('to_date').setSensitiveRange(value, '<?= date('Y-m-d') ?>');
            return true;
          case 'to_date':
            filterForm.getCalendar('from_date').setSensitiveRange(null, value);
            return true;
        }
      });
      filterForm.attachEvent('onButtonClick', id => {
        if (id === 'getdata') {
          const subplant = filterForm.getItemValue('subplant');
          const fromDate = filterForm.getItemValue('from_date');
          const toDate = filterForm.getItemValue('to_date');
          const status = filterForm.getItemValue('status');
          const type = filterForm.getItemValue('type');
          const reason = filterForm.getItemValue('reason');

          fetchAvailableRequests(subplant, fromDate, toDate, status, type, reason)
        }
      });

      return cell;
    }

    function fetchAvailableRequests(subplant, fromDate, toDate, status, type, reason) {
      const cell = rootLayout.cells(CELL_REQUEST_LIST);
      cell.progressOn();

      return WMSApi.qa.getDowngradeRequests(subplant, fromDate, toDate, status, type, reason)
        .then(requests => requests.map(request => Object.assign(request, { id: request.downgrade_id })))
        .then(requests => {
          cell.progressOff();
          const toolbar = cell.getAttachedToolbar();
          toolbar.disableItem('edit');

          const grid = cell.getAttachedObject();
          grid.clearAll();

          grid.parse(requests, 'js');
          toolbar.setItemText('timestamp', moment().format());

          if (requests.length === 0) {
            dhtmlx.message('Tidak ada permintaan downgrade berdasarkan permintaan yang dipilih!');
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
            id: 'add',
            text: 'Tambah Permintaan',
            img: 'fa fa-plus',
            imgdis: 'fa fa-plus'
          },
          {type: 'separator'},
          {
            type: 'button',
            id: 'edit',
            text: 'Ubah Permintaan',
            img: 'fa fa-edit',
            imgdis: 'fa fa-edit',
            enabled: false
          },
          {
            type: 'button',
            id: 'cancel',
            text: 'Batalkan Permintaan',
            img: 'fa fa-times',
            imgdis: 'fa fa-times',
            enabled: false
          },
          {type: 'separator'},
          {
            type: 'button',
            id: 'clear_filters',
            text: 'Bersihkan Penyaring Data',
            img: 'fa fa-close',
            imgdis: 'fa fa-close'
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
      <?php if(!UserRole::hasAnyRole(RoleAcl::downgradePalletsModification())): ?>
      toolbar.disableItem('add');
      <?php endif ?>

      toolbar.attachEvent('onClick', itemId => {
        const title = `Daftar Permintaan Downgrade Plant <?= PlantIdHelper::getCurrentPlant() ?>`;
        const filterForm = rootLayout.cells(CELL_REQUEST_FILTER).getAttachedObject();
        const fromDate = moment(filterForm.getItemValue('from_date')).format('YYYY-MM-DD');
        const toDate = moment(filterForm.getItemValue('to_date')).format('YYYY-MM-DD');
        const exportTitle = `${fromDate}-${toDate} - ${title}`;

        switch (itemId) {
          case 'add':
            openNewRequestWindow(windows);
            break;
          case 'edit':
            const selectedDowngradeId = requestGrid.getSelectedRowId();
            if (!selectedDowngradeId) {
              return;
            }
            const subplant = requestGrid.cells(selectedDowngradeId, requestGrid.getColIndexById('subplant')).getValue();
            const requestDate = requestGrid.cells(selectedDowngradeId, requestGrid.getColIndexById('request_date')).getValue();
            const status = requestGrid.cells(selectedDowngradeId, requestGrid.getColIndexById('status')).getValue();
            const type = requestGrid.cells(selectedDowngradeId, requestGrid.getColIndexById('type')).getValue();
            const reason = requestGrid.cells(selectedDowngradeId, requestGrid.getColIndexById('reason')).getValue();
            const createdBy = requestGrid.cells(selectedDowngradeId, requestGrid.getColIndexById('created_by')).getValue();

            openRequestDetails(windows, {
              id: selectedDowngradeId,
              subplant,
              requestDate,
              status,
              type,
              reason,
              createdBy,
              mode: 'edit'
            });
            break;
          case 'cancel':
            dhtmlx.prompt(windows, {
              title: 'Konfirmasi Pembatalan Downgrade',
              message: 'Mohon masukkan alasan pembatalan downgrade.',
              ok: 'OK',
              cancel: 'Batal'
            })
              .then(result => {
                if (!result) {
                  return;
                }
                const downgradeId = requestGrid.getSelectedRowId();
                if (!downgradeId) {
                  return;
                }

                rootLayout.cells(CELL_REQUEST_LIST).progressOn();
                cancelRequest(downgradeId, result)
                  .then(result => {
                    rootLayout.cells(CELL_REQUEST_LIST).progressOff();
                    upsertRequestAtRootGrid(result);
                  })
                  .catch(error => {
                    rootLayout.cells(CELL_REQUEST_LIST).progressOff();
                    handleApiError(error)
                  })
              });

            break;
          case 'clear_filters':
            gridUtils.clearAllGridFilters(requestGrid);
            break;
          case 'print': {
            const pdfColWidths = '25,30,120,70,70,70,70,*,40,40,80,50,0,0';
            requestGrid.setColumnHidden(requestGrid.getColIndexById('last_updated_at'), true);
            requestGrid.setColumnHidden(requestGrid.getColIndexById('last_updated_by'), true);
            cell.progressOn();
            gridUtils.generateFilteredPdf(requestGrid, exportTitle, USERID, pdfColWidths)
              .getBlob(blob => {
                requestGrid.setColumnHidden(requestGrid.getColIndexById('last_updated_at'), false);
                requestGrid.setColumnHidden(requestGrid.getColIndexById('last_updated_by'), false);
                cell.progressOff();
                gridUtils.openPDFWindow(exportTitle, exportTitle, blob);
              }, {autoPrint: true});
            break;
          }
          case 'export_csv':
            cell.progressOn();
            gridUtils.downloadFilteredCSV(requestGrid, exportTitle);
            cell.progressOff();
            break;
          case 'export_pdf': {
            const pdfColWidths = '25,30,120,70,70,70,70,*,40,40,80,50,0,0';
            requestGrid.setColumnHidden(requestGrid.getColIndexById('last_updated_at'), true);
            requestGrid.setColumnHidden(requestGrid.getColIndexById('last_updated_by'), true);
            cell.progressOn();
            const filename = exportTitle + '.pdf';
            gridUtils.generateFilteredPdf(requestGrid, title, USERID, pdfColWidths)
              .download(filename);
            requestGrid.setColumnHidden(requestGrid.getColIndexById('last_updated_at'), false);
            requestGrid.setColumnHidden(requestGrid.getColIndexById('last_updated_by'), false);
            cell.progressOff();
            break;
          }
        }
      });

      // initialize grid
      const FILTERS = gridUtils.headerFilters;
      const requestGrid = cell.attachGrid();
      requestGrid.setHeader(
        'NO.,SUBP.,NO. DWG.,TGL.,DIBUAT OLEH,STATUS,JENIS,KETERANGAN,JUM. PLT.,TTL. QTY,TERAKHIR DIUBAH,PENGUBAH,DISETUJUI OLEH,DISETUJUI PADA',
        null,
        [TEXT_RIGHT_ALIGN, '', '', '', '', '', '', '', '', TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, '', '', '', '']
      );
      requestGrid.setColumnIds(
        'no,subplant,downgrade_id,request_date,created_by,status,type,reason,pallet_count,total_pallet_quantity,last_updated_at,last_updated_by,approved_by,approved_at',
      );
      requestGrid.setColTypes('cntr,rotxt,rotxt,ro_date,rotxt,downgradeStatus,downgradeType,rotxt,ron,ron,ro_ts,rotxt,rotxt,ro_ts');
      requestGrid.setInitWidths('45,45,140,80,80,80,80,*,60,60,160,100,0,0');
      requestGrid.setColAlign('right,left,left,left,left,left,left,left,right,right,left,left,left,left');
      requestGrid.setColSorting('na,str,str,date,str,str,str,str,int,int,str,str,na,na');
      requestGrid.setColumnHidden(requestGrid.getColIndexById('approved_by'), true);
      requestGrid.setColumnHidden(requestGrid.getColIndexById('approved_at'), true);
      requestGrid.attachHeader([
        '&nbsp;', FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.NUMERIC, FILTERS.NUMERIC, FILTERS.TEXT, FILTERS.TEXT
      ], [
        '', '', '', '', '', '', '', '', TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, '', ''
      ]);
      requestGrid.attachFooter([
        'Total', COLUMN_SPAN, COLUMN_SPAN, COLUMN_SPAN, COLUMN_SPAN, COLUMN_SPAN, COLUMN_SPAN, COLUMN_SPAN,
        gridUtils.reducers.STATISTICS_TOTAL, gridUtils.reducers.STATISTICS_TOTAL, '', ''
      ], [
        TEXT_RIGHT_ALIGN + TEXT_BOLD, '', '', '', '', '', '', '', TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, '', ''
      ]);
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('pallet_count'), ",", ".");
      requestGrid.setNumberFormat("0,000", requestGrid.getColIndexById('total_pallet_quantity'), ",", ".");
      // requestGrid.enableSmartRendering(true, 100);
      requestGrid.init();

      // open details on double click
      requestGrid.attachEvent('onRowSelect', rowId => {
        const status = requestGrid.cells(rowId, requestGrid.getColIndexById('status')).getValue();
        if (status === '<?= PalletDowngrade::STATUS_OPEN ?>' && <?= UserRole::hasAnyRole(RoleAcl::downgradePalletsModification()) ?>) {
          toolbar.enableItem('edit');
          toolbar.enableItem('cancel');
        } else {
          toolbar.disableItem('edit');
          toolbar.disableItem('cancel');
        }
      });
      requestGrid.attachEvent('onRowDblClicked', rowId => {
        const downgradeId = rowId;
        const subplant = requestGrid.cells(rowId, requestGrid.getColIndexById('subplant')).getValue();
        const requestDate = requestGrid.cells(rowId, requestGrid.getColIndexById('request_date')).getValue();
        const status = requestGrid.cells(rowId, requestGrid.getColIndexById('status')).getValue();
        const type = requestGrid.cells(rowId, requestGrid.getColIndexById('type')).getValue();
        const reason = requestGrid.cells(rowId, requestGrid.getColIndexById('reason')).getValue();
        const createdBy = requestGrid.cells(rowId, requestGrid.getColIndexById('created_by')).getValue();
        const mode = status === '<?= PalletDowngrade::STATUS_OPEN ?>' ?
          '<?= UserRole::hasAnyRole(RoleAcl::downgradePalletsApproval()) ? 'approve' : 'view' ?>' : 'view';
        const approvedBy = requestGrid.cells(rowId, requestGrid.getColIndexById('approved_by')).getValue();
        const approvedAt = requestGrid.cells(rowId, requestGrid.getColIndexById('approved_at')).getValue();

        openRequestDetails(windows, {
          id: downgradeId,
          subplant,
          mode,
          requestDate,
          status,
          type,
          reason,
          createdBy,
          approvedBy,
          approvedAt
        });
      });

      return cell;
    }

    function openNewRequestWindow(windows) {
      const win = windows.createWindow('new_request', 0, 0, 360, 150);
      win.centerOnScreen();
      win.setText('Permintaan Downgrade Baru');
      win.button('park').hide();
      win.button('minmax').hide();
      win.setModal(true);

      // setup form
      const subplantOptions = SUBPLANTS_OPTIONS.map(subplant => ({text: subplant, value: subplant}));
      const typeOptions = Object.keys(TYPE_OPTIONS).map(val => ({text: TYPE_OPTIONS[val].label, value: val}));
      const formConfig = [
        {type: 'settings', position: 'label-left', labelWidth: 160, inputWidth: 160},
        {type: 'combo', readonly: true, required: true, name: 'subplant', label: 'Subplant', options: subplantOptions},
        {type: 'combo', readonly: true, required: true, name: 'type', label: 'Jenis', options: typeOptions},
        {
          type: 'block', blockOffset: 0, list: [
            {type: 'button', name: 'create', value: 'Buat'},
            {type: 'newcolumn'},
            {type: 'button', name: 'cancel', value: 'Batal'}
          ]
        }
      ];
      const form = win.attachForm(formConfig);
      form.attachEvent('onButtonClick', btnName => {
        switch (btnName) {
          case 'create':
            const subplant = form.getItemValue('subplant');
            const type = form.getItemValue('type');
            win.close();
            openRequestDetails(windows, { subplant, type, mode: 'create' });
            break;
          case 'cancel':
            win.close();
            break;
        }
      })
    }

    const REQUEST_FORM_WINDOW_ID = 'dwg_request_details';
    function refreshReasons() {
      if (!windows.isWindow(REQUEST_FORM_WINDOW_ID)) {
        return;
      }

      const window = windows.window(REQUEST_FORM_WINDOW_ID);
      const infoForm = window.getAttachedObject().cells('a').getAttachedObject();
      const iconNode = document.getElementById('refresh-reasons');
      infoForm.disableItem('reason');
      iconNode.classList.toggle('fa-spin');
      WMSApi.qa.getAvailableDowngradeReasons(true)
        .then(downgradeReasons => {
          Object.keys(REASONS_OPTIONS).forEach(key => {
            delete REASONS_OPTIONS[key];
          });
          downgradeReasons.forEach(reason => {
            REASONS_OPTIONS[reason.id] = reason.reason
          });
          return REASONS_OPTIONS;
        })
        .then(mappedDowngradeReasons => {
          const existingReason = infoForm.getItemValue('reason');
          const options = [];
          let existingReasonExists = false;
          Object.keys(mappedDowngradeReasons).forEach(reasonId => {
            const obj = {
              text: mappedDowngradeReasons[reasonId],
              value: mappedDowngradeReasons[reasonId],
              selected: mappedDowngradeReasons[reasonId] === existingReason
            };
            if (obj.selected) {
              existingReasonExists = true;
            }
            options.push(obj);
          });
          if (!existingReasonExists && options.length > 0) {
            options[0].selected = true;
          }

          // update the combo
          const reasonCombo = infoForm.getCombo('reason');
          reasonCombo.clearAll(false);
          if (options.length === 0) {
            reasonCombo.setComboText('N/A');
            reasonCombo.setComboValue('N/A');
            dhtmlx.message('Tidak ada keterangan downgrade yang tersedia!');
          }
          reasonCombo.addOption(options);
          infoForm.enableItem('reason');
          iconNode.classList.toggle('fa-spin');
        })
        .catch(error => {
          infoForm.enableItem('reason');
          iconNode.classList.toggle('fa-spin');
          handleApiError(error, window);
        })
    }

    function openRequestDetails(windows, options) {
      const MODE = options.mode;
      const win = windows.createWindow(REQUEST_FORM_WINDOW_ID, 0, 0, 700, 500);
      const winTitle = ['edit', 'approve', 'view'].includes(MODE) ? `Detail Permintaan Downgrade - ${options.id}` : 'Detail Permintaan Downgrade Baru';

      win.centerOnScreen();
      win.setText(winTitle);
      win.button("park").hide();
      win.setModal(true);
      win.maximize();
      win.attachEvent('onClose', window => {
        if (!['view', 'approve'].includes(MODE)) {
          if (window.skipWindowCloseEvent) {
            return true;
          }
          dhtmlx.confirm({
            title: MODE === 'edit' ? 'Batalkan Perubahan' : 'Batalkan Pembuatan',
            type: 'confirm-warning',
            text: 'Apakah Anda yakin ingin membatalkan? Semua perubahan akan hilang.',
            ok: 'Ya',
            no: 'Tidak',
            callback: confirmed => {
              if (confirmed) {
                window.skipWindowCloseEvent = true;
                window.close();
              }
            }
          });
          return false;
        } else {
          return true;
        }
      });

      const CELL_DETAILS_INFO = 'a';
      const CELL_DETAILS_PALLETS = 'b';
      const CELL_DETAILS_ACTIONS = 'c';

      const detailsLayout = win.attachLayout({
        pattern: '3E',
        cells: [
          {id: CELL_DETAILS_INFO, height: 120, fix_size: true, header: false},
          {id: CELL_DETAILS_PALLETS, fix_size: true, header: false},
          {id: CELL_DETAILS_ACTIONS, height: 60, fix_size: true, header: false}
        ]
      });

      // setup info
      const requestDate = options.requestDate || moment().format('D MMM YYYY');
      const reasonsOptions = Object.keys(REASONS_OPTIONS).map((reason, idx) => ({
        text: REASONS_OPTIONS[reason],
        value: REASONS_OPTIONS[reason],
        selected: options.reason ? reason === options.reason : idx === 0
      }));

      const reasonsInput = ['view', 'approve'].includes(MODE) ? {
        type: 'input', readonly: true, name: 'reason', label: 'Keterangan', value: options.reason
      } : {
        type: 'block', width: 350, list: [
          { type: 'combo', readonly: true, name: 'reason', label: 'Keterangan', options: reasonsOptions },
          { type: 'newcolumn' },
          {
            type: 'template',
            name: 'refresh-reasons',
            format: () => '<a href="javascript:void(0);" onclick="refreshReasons();"><i id="refresh-reasons" class="fa fa-refresh fa-2x"></i></a>',
            inputWidth: 25
          },
        ],
      };
      const firstBlock = {
        type: 'block',
        width: 280,
        list: [
          { type: 'input', readonly: true, name: 'subplant', label: 'Subplant', value: options.subplant },
          { type: 'input', readonly: true, name: 'type_view', label: 'Jenis', value: TYPE_OPTIONS[options.type].label },
          { type: 'hidden', name: 'type', label: 'Jenis', value: options.type },
          { type: 'input', readonly: true, name: 'request_date', label: 'Tanggal', value: requestDate },
        ]
      };
      const secondBlock = {
        type: 'block',
        width: 350,
        ofsetLeft: 20,
        list: [reasonsInput]
      };

      const infoFormConfig = [
        { type: 'settings', position: 'label-left', labelWidth: 80, inputWidth: 160 },
        firstBlock,
        { type: 'newcolumn' },
        secondBlock
      ];
      if (MODE === 'view' && options.status === '<?= PalletDowngrade::STATUS_APPROVED ?>') {
        const thirdBlock = {
          type: 'block',
          width: 280,
          list: [
            { type: 'input', readonly: true, name: 'approved_by', label: 'Disetujui Oleh', value: options.approvedBy },
            { type: 'input', readonly: true, name: 'approved_at', label: 'Disetujui Pada', value: options.approvedAt },
          ]
        };
        infoFormConfig.push({ type: 'newcolumn' });
        infoFormConfig.push(thirdBlock);
      }

      if (['edit', 'view', 'approve'].includes(MODE)) {
        secondBlock.list.push({
          type: 'hidden',
          name: 'status',
          value: options.status
        });
        secondBlock.list.push({
          type: 'hidden',
          name: 'id',
          value: options.id
        });
        secondBlock.list.push({
          type: 'input',
          readonly: true,
          name: 'status_view',
          label: 'Status',
          value: STATUS_OPTIONS[options.status]
        });
        secondBlock.list.push({
          type: 'input',
          readonly: true,
          name: 'created_by',
          label: 'Dibuat Oleh',
          value: options.createdBy
        })
      }
      const infoForm = detailsLayout.cells(CELL_DETAILS_INFO).attachForm(infoFormConfig);

      // setup grid + toolbar
      const toolbarConfig = {
        iconset: 'awesome',
        items: [
          {
            type: 'button',
            id: 'add_pallets',
            text: 'Tambah Palet',
            img: 'fa fa-plus',
            imgdis: 'fa fa-plus',
            enabled: false
          },
          {type: 'separator'},
          {
            type: 'button',
            id: 'remove_pallet',
            text: 'Hapus Palet',
            img: 'fa fa-trash',
            imgdis: 'fa fa-trash',
            enabled: false
          },
          {
            type: 'button',
            id: 'undo_remove_pallet',
            text: 'Batalkan Hapus Palet',
            img: 'fa fa-times',
            imgdis: 'fa fa-times',
            enabled: false
          },
          { type: 'separator' },
          { type: 'button', id: 'print', text: 'Cetak', img: 'fa fa-print', imgdis: 'fa fa-print', enabled: false },
          { type: 'button', id: 'export_csv', text: 'Ke CSV', img: 'fa fa-file-excel-o', imgdis: 'fa fa-file-excel-o', enabled: false },
          { type: 'button', id: 'export_pdf', text: 'Ke PDF', img: 'fa fa-file-pdf-o', imgdis: 'fa fa-file-pdf-o', enabled: false },
        ]
      };
      const toolbar = detailsLayout.cells(CELL_DETAILS_PALLETS).attachToolbar(toolbarConfig);
      if (['edit', 'create'].includes(MODE)) {
        toolbar.enableItem('add_pallets');
        toolbar.hideItem('print');
        toolbar.hideItem('export_csv');
        toolbar.hideItem('export_pdf');
      }
      if (['view', 'approve'].includes(MODE)) {
        toolbar.enableItem('print');
        toolbar.enableItem('export_csv');
        toolbar.enableItem('export_pdf');
      }

      toolbar.attachEvent('onClick', id => {
        switch (id) {
          case 'add_pallets': {
            const subplant = infoForm.getItemValue('subplant');
            const quality = TYPE_OPTIONS[infoForm.getItemValue('type')].quality_src;
            PalletsForBlocking.openAvailablePalletsWindow(windows, subplant, quality, win)
              .then(result => {
                if (!Array.isArray(result)) {
                  return;
                }
                if (result.length === 0) {
                  return;
                }
                result.forEach(pallet => {
                  console.log(pallet);
                  const targetRowId = pallet.pallet_no;
                  if (!grid.doesRowExist(targetRowId)) {
                    grid.addRow(targetRowId, '');
                    grid.setRowData(targetRowId, {
                      pallet_no: pallet.pallet_no,
                      current_motif_id: pallet.motif_id,
                      current_motif_name: pallet.motif_name,
                      new_motif_id: '-',
                      new_motif_name: '-',
                      production_date: pallet.production_date,
                      line: pallet.line,
                      creator_shift: pallet.creator_shift,
                      creator_group: pallet.creator_group,
                      size: pallet.size,
                      shading: pallet.shading,
                      quantity: pallet.current_quantity,
                      pallet_action: 'A'
                    });
                  }
                });

                // make sure that the counters and footer are updated accordingly.
                grid.resetCounter(0);
                grid.callEvent('onGridReconstructed', []);
                if (validateDetailsForm(grid)) {
                  actionForm.enableItem('save');
                }
              });
            break;
          }
          case 'remove_pallet': {
            // remove pallet: mark for deletion if pallet is existing, remove otherwise.
            const selectedRowId = grid.getSelectedRowId();
            if (!selectedRowId) {
              return;
            }

            const isExistingPallet = grid.cells(selectedRowId, grid.getColIndexById('new_motif_id')).getValue() !== '-';
            if (isExistingPallet) {
              grid.cells(selectedRowId, grid.getColIndexById('pallet_action')).setValue('D');
              toolbar.enableItem('undo_remove_pallet');
            } else {
              grid.deleteRow(selectedRowId);
            }
            toolbar.disableItem(id);
            if (validateDetailsForm(grid)) {
              actionForm.enableItem('save');
            } else {
              actionForm.disableItem('save');
            }
            break;
          }
          case 'undo_remove_pallet': {
            const selectedRowId = grid.getSelectedRowId();
            if (!selectedRowId) {
              return;
            }

            const isExistingPallet = grid.cells(selectedRowId, grid.getColIndexById('new_motif_id')).getValue() !== '-';
            if (isExistingPallet) {
              grid.cells(selectedRowId, grid.getColIndexById('pallet_action')).setValue(null);
            }
            toolbar.enableItem('remove_pallet');
            toolbar.disableItem(id);

            if (validateDetailsForm(grid)) {
              actionForm.enableItem('save');
            } else {
              actionForm.disableItem('save');
            }
            break;
          }
          case 'print': {
            const details = {
              id: infoForm.getItemValue('id'),
              subplant: infoForm.getItemValue('subplant'),
              type: infoForm.getItemValue('type'),
              request_date: infoForm.getItemValue('request_date'),
              reason: infoForm.getItemValue('reason'),
              status: infoForm.getItemValue('status') || null,
              created_by: infoForm.getItemValue('created_by') || null,
              approved_by: infoForm.getItemValue('approved_by') || null,
              approved_at: infoForm.getItemValue('approved_at') || null
            };
            win.progressOn();
            exportDetailsAsPdf(details, grid)
              .getBlob(blob => {
                win.progressOff();
                gridUtils.openPDFWindow(winTitle, winTitle, blob);
              }, {autoPrint: true});
            break;
          }
          case 'export_csv': {
            win.progressOn();
            const exportTitle = winTitle;
            gridUtils.downloadFilteredCSV(grid, exportTitle);
            win.progressOff();
            break;
          }
          case 'export_pdf': {
            const details = {
              id: infoForm.getItemValue('id'),
              subplant: infoForm.getItemValue('subplant'),
              type: infoForm.getItemValue('type'),
              request_date: infoForm.getItemValue('request_date'),
              reason: infoForm.getItemValue('reason'),
              status: infoForm.getItemValue('status') || null,
              created_by: infoForm.getItemValue('created_by') || null,
              approved_by: infoForm.getItemValue('approved_by') || null,
              approved_at: infoForm.getItemValue('approved_at') || null
            };
            win.progressOn();
            const filename = `Detail Permintaan Downgrade - ${details.id}` + '.pdf';
            exportDetailsAsPdf(details, grid)
              .download(filename);
            win.progressOff();
            break;
          }
        }
      });
      const grid = detailsLayout.cells(CELL_DETAILS_PALLETS).attachGrid();
      grid.setHeader('NO.,NO. PLT.,KD. MOTIF LAMA,MOTIF LAMA,KD. MOTIF BARU,MOTIF BARU,TGL. PROD.,LINE,REGU,SHIFT,SZ.,SHADE.,QTY.,AKSI', null, [
        TEXT_RIGHT_ALIGN, '', '', '', '', '', '', TEXT_RIGHT_ALIGN, '', TEXT_RIGHT_ALIGN, '', '', TEXT_RIGHT_ALIGN, ''
      ]);
      grid.setColumnIds('no,pallet_no,current_motif_id,current_motif_name,new_motif_id,new_motif_name,production_date,line,creator_group,creator_shift,size,shading,quantity,pallet_action');
      grid.setColSorting('na,str,str,str,str,str,str,int,str,str,str,str,int,na');
      grid.setColAlign('right,left,left,left,left,left,left,right,left,right,left,left,right,left');
      grid.setColTypes('cntr,rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,ron,rotxt,ron,rotxt,rotxt,ron,ro');
      grid.setInitWidths('40,130,60,*,60,*,80,50,50,50,60,60,80,10');
      grid.attachFooter([
        '&nbsp;', 'Total', COLUMN_SPAN, COLUMN_SPAN, COLUMN_SPAN, COLUMN_SPAN,
        COLUMN_SPAN, COLUMN_SPAN, COLUMN_SPAN, COLUMN_SPAN, COLUMN_SPAN,
        gridUtils.reducers.STATISTICS_COUNT, gridUtils.reducers.STATISTICS_TOTAL, '&nbsp;'
      ], [
        '', gridUtils.styles.TEXT_RIGHT_ALIGN + gridUtils.styles.TEXT_BOLD, '', '', '', '',
        '', '', '', '', '', gridUtils.styles.TEXT_RIGHT_ALIGN + gridUtils.styles.TEXT_BOLD, gridUtils.styles.TEXT_RIGHT_ALIGN + gridUtils.styles.TEXT_BOLD, ''
      ]);

      grid.setColumnHidden(grid.getColIndexById('current_motif_id'), true);
      grid.setColumnHidden(grid.getColIndexById('new_motif_id'), true);
      grid.setColumnHidden(grid.getColIndexById('pallet_action'), true);
      grid.setNumberFormat("0,000", grid.getColIndexById('quantity'), ",", ".");

      grid.attachEvent('onCellChanged', (rowId, colIdx, newValue) => {
        if (colIdx === grid.getColIndexById('pallet_action')) {
          switch (newValue) {
            case 'A': // add
              grid.setRowColor(rowId, 'lime');
              break;
            case 'D': // delete
              grid.setRowColor(rowId, 'red');
              break;
            default:
              grid.setRowColor(rowId, 'white');
              break;
          }
        }
      });
      grid.attachEvent('onRowSelect', rowId => {
        if (['create', 'edit'].includes(MODE)) {
          const palletAction = grid.cells(rowId, grid.getColIndexById('pallet_action')).getValue();
          if (palletAction === 'D') {
            toolbar.disableItem('remove_pallet');
            toolbar.enableItem('undo_remove_pallet');
          } else {
            toolbar.enableItem('remove_pallet');
            toolbar.disableItem('undo_remove_pallet');
          }
        }
      });
      grid.init();

      // load initial data
      if (['approve', 'edit', 'view'].includes(MODE)) {
        // preload the data
        win.progressOn();
        fetchRequestDetails(options.id)
          .then(result => {
            const requestStatus = infoForm.getItemValue('status');
            const rejectedStatus = ['<?= PalletDowngrade::STATUS_CANCELLED ?>', '<?= PalletDowngrade::STATUS_REJECTED ?>'];
            const isRejected = rejectedStatus.includes(requestStatus);
            return result.map(pallet => Object.assign(pallet, {
              id: pallet.pallet_no,
              pallet_action: null,
              // show the new motif only if it is approved. otherwise, just hide it.
              new_motif_id: isRejected ? '-' : pallet.new_motif_id,
              new_motif_name: isRejected ? '-' : pallet.new_motif_name
            }))
          })
          .then(pallets => {
            win.progressOff();
            grid.clearAll();
            grid.parse(pallets, 'js');
          })
          .catch(error => {
            win.progressOff();
            handleApiError(error, win);
          })
      }

      // setup buttons
      const buttons = [
        {type: 'button', name: 'close', value: 'Tutup'}
      ];
      if (['edit', 'create'].includes(MODE)) {
        buttons.unshift({ type: 'newcolumn' });
        buttons.unshift({ type: 'button', name: 'save', value: 'Simpan' });
      } else if (MODE === 'approve') {
        buttons.unshift({ type: 'newcolumn' });
        buttons.unshift({type: 'button', name: 'reject', value: 'Tolak', className: 'btn-danger'});
        buttons.unshift({ type: 'newcolumn' });
        buttons.unshift({type: 'button', name: 'approve', value: 'Setuju', className: 'btn-success'});
      }
      const actionFormConfig = [
        {type: 'block', list: buttons}
      ];
      const actionForm = detailsLayout.cells(CELL_DETAILS_ACTIONS).attachForm(actionFormConfig);
      if (MODE === 'create') {
        actionForm.disableItem('save');
      }
      actionForm.attachEvent('onButtonClick', btnName => {
        switch (btnName) {
          case 'close': {
            win.close();
            break;
          }
          case 'save': {
            // collect pallets going to be added/removed
            const palletsToAdd = [];
            const palletsToRemove = [];
            grid.forEachRow(rowId => {
              const palletAction = grid.cells(rowId, grid.getColIndexById('pallet_action')).getValue();
              if (palletAction === 'A') {
                palletsToAdd.push(grid.getRowData(rowId));
              } else if (palletAction === 'D') {
                palletsToRemove.push(grid.getRowData(rowId));
              }
            });

            // generate output text.
            let text = '';
            if (palletsToAdd.length > 0) {
              text += `${palletsToAdd.length} palet berikut akan ditambahkan dalam permintaan downgrade:<br/><ol>`;
              palletsToAdd.forEach(pallet => {
                text += `<li>${pallet.pallet_no}: ${pallet.quantity} m<sup>2</sup></li>`;
              });
              text += '</ol><br/>';
            }

            if (palletsToRemove.length > 0) {
              text += `${palletsToRemove.length} palet berikut akan dihapus dari permintaan downgrade:<br/><ol>`;
              palletsToRemove.forEach(pallet => {
                text += `<li>${pallet.pallet_no}: ${pallet.quantity} m<sup>2</sup></li>`;
              });
              text += '</ol><br/>';
            }
            text += 'Apakah Anda yakin dengan permintaan ini?';

            dhtmlx.confirm({
              title: MODE === 'create' ? 'Konfirmasi Permintaan Downgrade' : 'Konfirmasi Perubahan Permintaan Downgrade',
              text: text,
              ok: 'OK',
              cancel: 'Batal',
              callback: confirmed => {
                if (!confirmed) {
                  return;
                }

                const reason = infoForm.getItemValue('reason');
                win.progressOn();
                if (MODE === 'create') {
                  const subplant = infoForm.getItemValue('subplant');
                  const type = infoForm.getItemValue('type');
                  createRequest(subplant, palletsToAdd.map(pallet => pallet.pallet_no), type, reason)
                    .then(result => {
                      win.progressOff();
                      upsertRequestAtRootGrid(result);
                      win.skipWindowCloseEvent = true;
                      win.close();
                    })
                    .catch(error => {
                      win.progressOff();
                      handleApiError(error, win);
                    })
                } else { // edit
                  const downgradeId = infoForm.getItemValue('id');
                  updateRequestDetails(downgradeId, reason,
                    palletsToAdd.map(pallet => pallet.pallet_no),
                    palletsToRemove.map(pallet => pallet.pallet_no)
                  )
                    .then(result => {
                      win.progressOff();
                      upsertRequestAtRootGrid(result);
                      win.skipWindowCloseEvent = true;
                      win.close();
                    })
                    .catch(error => {
                      win.progressOff();
                      handleApiError(error, win);
                    })
                }
              }
            });
            break;
          }
          case 'approve': {
            let palletCount = 0;
            let totalQuantity = 0;
            grid.forEachRow(rowId => {
              palletCount++;
              totalQuantity += grid.cells(rowId, grid.getColIndexById('quantity')).getValue()
            });
            dhtmlx.confirm({
              title: 'Konfirmasi Downgrade',
              text: `Apakah Anda yakin ingin mengubah ${palletCount} palet dari ${TYPE_OPTIONS[options.type].label}? ${totalQuantity} m<sup>2</sup> akan turun kualitas.`,
              ok: 'Ya',
              cancel: 'Tidak',
              callback: confirmed => {
                if (!confirmed) {
                  return;
                }
                win.progressOn();
                approveRequest(options.id, true)
                  .then(result => {
                    win.progressOff();
                    upsertRequestAtRootGrid(result);
                    win.close();
                  })
                  .catch(error => {
                    win.progressOff();
                    handleApiError(error, win);
                  })
              }
            });
            break;
          }
          case 'reject': {
            dhtmlx.prompt(windows, {
              title: 'Konfirmasi Penolakan Downgrade',
              message: 'Mohon masukkan alasan penolakan downgrade.',
              ok: 'Masukkan',
              cancel: 'Batal'
            })
              .then(result => {
                if (!result) {
                  return;
                }

                const id = infoForm.getItemValue('id');
                win.progressOn();
                approveRequest(id, false, result)
                  .then(result => {
                    win.progressOff();
                    upsertRequestAtRootGrid(result);
                    win.close();
                  })
                  .catch(error => {
                    win.progressOff();
                    handleApiError(error);
                  })
              });
            break;
          }
        }
      });
    }

    function validateDetailsForm(grid) {
      let palletsCount = 0;
      grid.forEachRow(rowId => {
        const action = grid.cells(rowId, grid.getColIndexById('pallet_action')).getValue();
        if (action === null || action === '' || action === 'A') {
          palletsCount++;
        }
      });

      return palletsCount > 0;
    }

    function exportDetailsAsPdf(details, grid) {
      const timestamp = new Date();
      const generatedAt = `Dibuat oleh [${USERID}] pada ${timestamp.toLocaleString(gridUtils.DEFAULT_LOCALE, gridUtils.DEFAULT_LOCALE_DATETIME_OPTIONS)}`;
      const colWidths = '25,120,60,*,60,*,70,35,40,40,30,50,40,10';
      const title = `Detail Permintaan Downgrade - ${details.id}`;

      const tableBody = [];
      const hasCustomColumnWidths = colWidths !== '';
      const columnWidths = [];
      const customColumnWidths = colWidths.split(',').map(width => {
        const result = parseInt(width);
        return isNaN(result) ? width : result;
      });
      if (hasCustomColumnWidths && grid.getColumnsNum() !== customColumnWidths.length) {
        throw new Error('Number of supplied custom column width does not match with number of columns in grid (including hidden ones)!')
      }

      const infoBody = {
        columns: [
          {
            alignment: 'right',
            width: 'auto',
            stack: [
              { style: 'strong', text: 'Subplant' },
              { style: 'strong', text: 'Jenis' },
              { style: 'strong', text: 'Tanggal' }
            ]
          },
          {
            alignment: 'left',
            width: '*',
            margin: [5, 0],
            stack: [
              { style: 'strong', text: details.subplant },
              { style: 'strong', text: TYPE_OPTIONS[details.type].label },
              { style: 'strong', text: details.request_date }
            ]
          },
          {
            alignment: 'right',
            width: 'auto',
            stack: [
              { style: 'strong', text: 'Keterangan' },
              { style: 'strong', text: 'Status' },
              { style: 'strong', text: 'Dibuat Oleh' }
            ]
          },
          {
            alignment: 'left',
            width: '*',
            margin: [5, 0],
            stack: [
              { style: 'strong', text: details.reason },
              { style: 'strong', text: STATUS_OPTIONS[details.status] },
              { style: 'strong', text: details.created_by }
            ]
          },
        ]
      };

      if (details.status === '<?= PalletDowngrade::STATUS_APPROVED ?>') {
        infoBody.columns.push({
          alignment: 'right',
          width: 'auto',
          stack: [
            { style: 'strong', text: 'Disetujui Oleh' },
            { style: 'strong', text: 'Disetujui Pada' }
          ]
        });
        infoBody.columns.push({
          alignment: 'left',
          width: '*',
          margin: [5, 0],
          stack: [
            { style: 'strong', text: details.approved_by },
            { style: 'strong', text: details.approved_at }
          ]
        })
      }

      const docDefinition = {
        info: {
          title: title
        },
        // page setup
        pageOrientation: 'landscape',
        pageSize: 'A4',
        pageMargins: [40, 60],

        header: {
          stack: [
            title,
            {
              text: '',
              style: 'subheader'
            }
          ],
          style: 'header',
          margin: [0, 20]
        },
        footer: (currentPage, pageCount) => ({
          columns: [
            generatedAt,
            // TODO i18n
            {text: `Halaman ${currentPage} dari ${pageCount}`, alignment: 'right'}
          ],
          margin: [40, 30, 40, 50],
          fontSize: 10
        }),

        content: [
          infoBody,
          {
            table: {
              style: 'table',
              headerRows: 1,
              widths: columnWidths,
              body: tableBody,
              layout: {
                fillColor: function (i, node) {
                  return (i % 2 === 0) ? '#CCCCCC' : null;
                }
              }
            }
          }
        ],

        styles: {
          header: {
            fontSize: 14,
            bold: true,
            margin: [0, 0, 0, 10],
            alignment: 'center'
          },
          strong: {
            bold: true
          },
          subheader: {
            fontSize: 8,
            bold: false,
            margin: [0, 0, 0, 10],
            alignment: 'center'
          },
          table: {
            margin: [0, 5, 0, 15]
          },
          tableHeader: {
            bold: true,
            fontSize: 13,
            color: 'black'
          },
          tableFooter: {
            bold: true,
            fontSize: 12,
            color: 'black'
          }
        },
      };

      // check if column hidden. if not hidden, add it as the first row
      // also, extract the alignment.
      // also, extract the filters.
      const filters = [];
      const TEXT_TYPES = ['ro', 'rotxt', 'ro_ts'];
      const tableHeaders = [];
      for (let i = 0; i < grid.getColumnsNum(); i++) {
        if (!grid.isColumnHidden(i) && grid.getColType(i) !== 'ch') {
          const label = grid.getColumnLabel(i, 0);

          const el_filter = grid.getFilterElement(i);
          if (el_filter) {
            const val_filter = el_filter.value;
            if (val_filter) {
              const type = grid.getColType(i);

              let label_filter;
              if (TEXT_TYPES.includes(type)) {
                const nodeName = el_filter.nodeName;
                label_filter = nodeName === 'INPUT' ? `${label} LIKE '${val_filter}'` : `${label} = '${val_filter}'`
              } else if (type === 'ron') {
                label_filter = !isNaN(parseFloat(val_filter)) && isFinite(val_filter) ? `${label} = ${val_filter}` : // fixed selection
                  `${label} ${val_filter}`; // equation
              } else {
                label_filter = `${label} = ${val_filter}`
              }
              filters.push(label_filter)
            }
          }

          tableHeaders.push({
            text: label,
            alignment: grid.cellAlign[i],
            style: 'tableHeader'
          });
          if (!hasCustomColumnWidths) {
            columnWidths.push(grid.initCellWidth[i]);
          } else {
            columnWidths.push(customColumnWidths[i]);
          }
        }
      }
      docDefinition.header.stack[1].text = filters.join(', '); // set subheader
      tableBody.push(tableHeaders);

      // check if column hidden. if not hidden, add it to the element.
      // also, extract the alignment.
      const visibleRowCount = grid.getRowsNum();
      for (let row = 0; row < visibleRowCount; row++) {
        const rowId = grid.getRowId(row);
        const tableRow = [];
        grid.forEachCell(rowId, (cell, colIndex) => {
          if (!grid.isColumnHidden(colIndex) && grid.getColType(colIndex) !== 'ch') {
            const columnId = grid.getColumnId(colIndex);
            const cellValue = cell.getTitle();
            tableRow.push({
              text: columnId === 'no' ? row + 1 : cellValue,
              alignment: grid.cellAlign[colIndex]
            })
          }
        });
        tableBody.push(tableRow);
      }

      // append footer
      // for now only handles the first row.
      const hasFooter = grid.ftr !== undefined;
      if (hasFooter) {
        const rowFooter = [];
        let lastFooter = {};
        for (let i = 0; i < grid.getColumnsNum(); i++) {
          if (!grid.isColumnHidden(i) && grid.getColType(i) !== 'ch') {
            const colValue = grid.getFooterLabel(i);
            if (colValue !== '#cspan' && colValue !== '') {
              lastFooter = {
                text: colValue,
                style: 'tableFooter',
                colSpan: 1,
                alignment: 'right'
              };
              rowFooter.push(lastFooter);
            } else {
              if (lastFooter.hasOwnProperty('colSpan')) {
                lastFooter.colSpan++;
              }
              rowFooter.push({});
            }
          }
        }
        tableBody.push(rowFooter);
      }
      return pdfMake.createPdf(docDefinition);
    }

    function fetchRequestDetails(downgradeId) {
      return WMSApi.qa.getDowngradeDetails(downgradeId);
    }

    function createRequest(requestedSubplant, palletNos, type, reason) {
      return WMSApi.qa.createDowngradeRequest(requestedSubplant, palletNos, type, reason);
    }

    function approveRequest(downgradeId, isApproved, reason = null) {
      return WMSApi.qa.approveDowngradeRequest(downgradeId, isApproved, reason);
    }

    function cancelRequest(downgradeId, reason = null) {
      return WMSApi.qa.cancelDowngradeRequest(downgradeId, reason);
    }

    function updateRequestDetails(downgradeId, newReason, palletsToAdd, palletsToRemove = []) {
      return WMSApi.qa.updateDowngradeRequest(downgradeId, newReason, palletsToAdd, palletsToRemove);
    }

    function upsertRequestAtRootGrid(updatedRequest) {
      const grid = rootLayout.cells(CELL_REQUEST_LIST).getAttachedObject();
      const rowId = updatedRequest.downgrade_id;
      if (!grid.doesRowExist(rowId)) {
        grid.addRow(rowId, '')
      }
      grid.setRowData(rowId, updatedRequest);

      // make sure the counter and footer is recalculated
      grid.resetCounter(0);
      grid.callEvent('onGridReconstructed', []);
    }
  </script>
</head>
<body onload="doOnLoad()">

</body>
</html>
