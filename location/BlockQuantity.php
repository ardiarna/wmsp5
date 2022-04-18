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

$customers = array();
try {
  $db = PostgresqlDatabase::getInstance();
  $customersQuery = "SELECT customer_nama FROM tbl_customer WHERE customer_nama is not null and customer_nama <> '' and customer_nama <> '-' order by customer_nama";
  $customersResult = $db->rawQuery($customersQuery);
  assert(pg_num_rows($customersResult) > 0);
  $i = 0;
  while ($row = pg_fetch_assoc($customersResult)) {
    $customer_nama = trim($row['customer_nama']);
    $customers[$customer_nama] = $customer_nama;
    $kustomerjs->data[$i]['customer'] = $customer_nama;
    $i++;
  }
  $db->close();
} catch (PostgresqlDatabaseException $e) {
  http_response_code(HttpUtils::HTTP_RESPONSE_SERVER_ERROR);
  die('Gagal mendapatkan daftar customer: ' . $e->getMessage() . ".\n" . (Env::isDebug() ? $e->getTraceAsString() : ''));
}
?>
<!DOCTYPE HTML>
<html lang="id">
<head>

  <title>Block Quantity</title>
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
  <script src="../assets/js/views/items-for-blocking-3.js"></script>
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
    const CUSTOMER_OPTIONS = <?= json_encode($customers) ?>;

    const kustomer_js = <?= json_encode($kustomerjs) ?>;

    const STATUS_OPTIONS = {'O':'Dalam Proses','S':'Terkirim','C':'Batal'};

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
          {id: CELL_REQUEST_FILTER, header: true, text: 'Block Quantity', height: 90},
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
      const statusOptions = [ALL].concat(Object.keys(STATUS_OPTIONS).map(val => ({
        text: STATUS_OPTIONS[val],
        value: val
      })));
    
      const filterFormConfig = [
        {type: "settings", position: "label-left", labelWidth: 50, inputWidth: 160},
        {
          type: 'calendar',
          offsetLeft: 20,
          name: 'from_date',
          label: 'Dari',
          enableTodayButton: true,
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
          readonly: true,
          dateFormat: "%Y-%m-%d",
          calendarPosition: "right",
          inputWidth: 100,
          value: '<?= date('Y-m-d') ?>' // first day of current month
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
        {
          type: 'combo',
          name: 'status',
          label: 'Status',
          options: statusOptions,
          readonly: true,
          offsetLeft: 20,
          inputWidth: 80
        },
        {type: 'newcolumn'},
        {type: 'button', offsetLeft: 20, name: 'getdata', value: 'Dapatkan Data!'}
      ];
      const filterForm = cell.attachForm(filterFormConfig);

      filterForm.attachEvent('onButtonClick', id => {
        if (id === 'getdata') {
          const subplant = filterForm.getItemValue('subplant');
          const fromDate = filterForm.getItemValue('from_date');
          const toDate = filterForm.getItemValue('to_date');
          const status = filterForm.getItemValue('status');
          fetchAvailableRequests(subplant, fromDate, toDate, status);
        }
      });

      return cell;
    }

    function fetchAvailableRequests(subplant, fromDate, toDate, status) {
      const cell = rootLayout.cells(CELL_REQUEST_LIST);
      cell.progressOn();

      return WMSApi.location.getBlockQuantityRequests(subplant, fromDate, toDate, status)
        .then(requests => requests.map(request => Object.assign(request, { id: request.order_id })))
        .then(requests => {
          cell.progressOff();
          const toolbar = cell.getAttachedToolbar();
          toolbar.disableItem('edit');
          toolbar.disableItem('cancom');

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
            id: 'add',
            text: 'Tambah',
            img: 'fa fa-plus',
            imgdis: 'fa fa-plus'
          },
          {
            type: 'button',
            id: 'edit',
            text: 'Ubah',
            img: 'fa fa-edit',
            imgdis: 'fa fa-edit',
            enabled: false
          },
          {type: 'separator'},
          {
            type: 'button',
            id: 'cancom',
            text: 'Batalkan / Block Selesai',
            img: 'fa fa-times',
            imgdis: 'fa fa-times',
            enabled: false
          },
          {type: 'separator'},
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
      <?php if(!UserRole::hasAnyRole(RoleAcl::blockQuantity())): ?>
      toolbar.disableItem('add');
      <?php endif ?>

      toolbar.attachEvent('onClick', itemId => {
        const title = `Block Quantity Plant <?= PlantIdHelper::getCurrentPlant() ?>`;
        const exportTitle = `${title}`;
        if(itemId === 'add') {
          openNewRequestWindow(windows);
        } else if(itemId === 'edit' || itemId === 'cancom') {
          const selectedOrderId = requestGrid.getSelectedRowId();
          if (!selectedOrderId) {
            return;
          }
          const subplant = requestGrid.cells(selectedOrderId, requestGrid.getColIndexById('subplant')).getValue();
          const orderTargetDate = requestGrid.cells(selectedOrderId, requestGrid.getColIndexById('order_target_date')).getValue();
          const customer = requestGrid.cells(selectedOrderId, requestGrid.getColIndexById('customer_id')).getValue();
          const keterangan = requestGrid.cells(selectedOrderId, requestGrid.getColIndexById('keterangan')).getValue();
          const createUser = requestGrid.cells(selectedOrderId, requestGrid.getColIndexById('create_user')).getValue();
          const createDate = requestGrid.cells(selectedOrderId, requestGrid.getColIndexById('create_date')).getValue();
          const mode = itemId;
          openRequestDetails(windows, {
            id: selectedOrderId,
            subplant,
            orderTargetDate,
            customer,
            keterangan,
            createUser,
            createDate,
            mode
          });
        } else if(itemId === 'clear_filters') {
          gridUtils.clearAllGridFilters(requestGrid);
        } else if(itemId === 'print') {
          const pdfColWidths = '25,30,120,*,60,60,60,120,60,120,120,80';
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
        } else if(itemId === 'export_csv') {
          cell.progressOn();
          gridUtils.downloadFilteredCSV(requestGrid, exportTitle);
          cell.progressOff();
        } else if(itemId === 'export_pdf') {
          const pdfColWidths = '25,30,120,*,60,60,60,120,60,120,120,80';
          requestGrid.setColumnHidden(requestGrid.getColIndexById('last_updated_at'), true);
          requestGrid.setColumnHidden(requestGrid.getColIndexById('last_updated_by'), true);
          cell.progressOn();
          const filename = exportTitle + '.pdf';
          gridUtils.generateFilteredPdf(requestGrid, title, USERID, pdfColWidths)
            .download(filename);
          requestGrid.setColumnHidden(requestGrid.getColIndexById('last_updated_at'), false);
          requestGrid.setColumnHidden(requestGrid.getColIndexById('last_updated_by'), false);
          cell.progressOff();
        }
      });

      // initialize grid
      const FILTERS = gridUtils.headerFilters;
      const requestGrid = cell.attachGrid();
      requestGrid.setHeader('NO.,SUBP.,NO. DOC.,CUSTOMER,TARGET DATE,TARGET DATE,STATUS,KETERANGAN,DIBUAT OLEH,DIBUAT TGL,TERAKHIR DIUBAH,PENGUBAH');
      requestGrid.setColumnIds('no,subplant,order_id,customer_id,order_target_date,order_target_date_v,order_status,keterangan,create_user,create_date,last_updated_at,last_updated_by');
      requestGrid.setColTypes('cntr,rotxt,rotxt,rotxt,rotxt,ro_date,orderStatus,rotxt,rotxt,ro_ts,ro_ts,rotxt');
      requestGrid.setInitWidths('45,45,140,*,80,80,80,140,80,160,160,100');
      requestGrid.setColAlign('right,left,left,left,left,left,left,left,left,left,left,left');
      requestGrid.setColSorting('na,str,str,str,date,date,str,str,str,date,str,str');
      requestGrid.setColumnHidden(requestGrid.getColIndexById('order_target_date'), true);
      requestGrid.setColumnHidden(requestGrid.getColIndexById('create_date'), true);
      requestGrid.attachHeader([
        '&nbsp;', FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT
      ]);
      requestGrid.enableSmartRendering(true, 100);
      requestGrid.init();

      // open details on double click
      requestGrid.attachEvent('onRowSelect', rowId => {
        const status = requestGrid.cells(rowId, requestGrid.getColIndexById('order_status')).getValue();
        if (status === 'O' && <?= UserRole::hasAnyRole(RoleAcl::blockQuantity()) ?>) {
          toolbar.enableItem('edit');
          toolbar.enableItem('cancom');
        } else {
          toolbar.disableItem('edit');
          toolbar.disableItem('cancom');
        }
      });
      requestGrid.attachEvent('onRowDblClicked', rowId => {
        const orderId = rowId;
        const subplant = requestGrid.cells(rowId, requestGrid.getColIndexById('subplant')).getValue();
        const orderTargetDate = requestGrid.cells(rowId, requestGrid.getColIndexById('order_target_date')).getValue();
        const customer = requestGrid.cells(rowId, requestGrid.getColIndexById('customer_id')).getValue();
        const keterangan = requestGrid.cells(rowId, requestGrid.getColIndexById('keterangan')).getValue();
        const createUser = requestGrid.cells(rowId, requestGrid.getColIndexById('create_user')).getValue();
        const createDate = requestGrid.cells(rowId, requestGrid.getColIndexById('create_date')).getValue();
        const mode = 'view';
        
        openRequestDetails(windows, {
          id: orderId,
          mode,
          subplant,
          orderTargetDate,
          customer,
          keterangan,
          createUser,
          createDate
        });
      });

      return cell;
    }

    function openNewRequestWindow(windows) {
      const win = windows.createWindow('new_request', 0, 0, 300, 120);
      win.centerOnScreen();
      win.setText('Block Quantity Baru');
      win.button('park').hide();
      win.button('minmax').hide();
      win.setModal(true);

      // setup form
      const subplantOptions = SUBPLANTS_OPTIONS.map(subplant => ({text: subplant, value: subplant}));

      const formConfig = [
        {type: 'settings', position: 'label-left', labelWidth: 90, inputWidth: 160},
        {type: 'combo', readonly: true, required: true, name: 'subplant', label: 'Subplant', options: subplantOptions},
        {
          type: 'block', offsetLeft: 72, list: [
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
            win.close();
            openRequestDetails(windows, { subplant, mode: 'create' });
            break;
          case 'cancel':
            win.close();
            break;
        }
      })
    }

    function openRequestDetails(windows, options) {
      const MODE = options.mode;
      const win = windows.createWindow('dwg_request_details', 0, 0, 700, 500);
      const winTitle = ['edit', 'view', 'cancom'].includes(MODE) ? `Detail Block Quantity - ${options.id}` : 'Detail Block Quantity Baru';

      win.centerOnScreen();
      win.setText(winTitle);
      win.button("park").hide();
      win.setModal(true);
      win.maximize();
      win.attachEvent('onClose', window => {
        if (!['view', 'cancom'].includes(MODE)) {
          if (window.skipWindowCloseEvent) {
            return true;
          }
          dhtmlx.confirm({
            title: MODE === 'edit' ? 'Batalkan Perubahan' : 'Batalkan Pembuatan',
            type: 'confirm-warning',
            text: 'Apakah Anda yakin ingin membatalkan? Semua perubahan akan hilang.',
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
      const CELL_DETAILS_ITEMS = 'b';
      const CELL_DETAILS_ACTIONS = 'c';

      const detailsLayout = win.attachLayout({
        pattern: '3E',
        cells: [
          {id: CELL_DETAILS_INFO, height: 110, fix_size: true, header: false},
          {id: CELL_DETAILS_ITEMS, fix_size: true, header: false},
          {id: CELL_DETAILS_ACTIONS, height: 60, fix_size: true, header: false}
        ]
      });

      // setup info
      const orderTargetDate = options.orderTargetDate || moment().format('YYYY-MM-DD');
      const customerOptions = Object.keys(CUSTOMER_OPTIONS).map((customer, idx) => ({
        text: CUSTOMER_OPTIONS[customer],
        value: CUSTOMER_OPTIONS[customer],
        selected: options.customer ? customer === options.customer : idx === 0
      }));

      const customerInput = ['view', 'cancom'].includes(MODE) ? {
        type: 'input', readonly: true, name: 'customer', label: 'Customer', inputWidth: 500, value: options.customer
      } : {
        type: "block", offsetTop: 0, width: 585, blockOffset: 0, list: [
        {
          type: 'template',
          label: 'Customer',
          name: 'reqbr',
          format: () => '<a href="javascript:void(0);" onclick="showCustomer(infoForm);"><i class="fa fa-search fa-2x"></i></a>', 
          inputWidth: 25
        },
        {type:"newcolumn"},
        {type: 'combo', readonly: true, name: 'customer', inputWidth: 472, options: customerOptions}
      ]};

      const targetDateInput = ['view', 'cancom'].includes(MODE) ? {
        type: 'input', readonly: true, name: 'order_target_date', label: 'Target Date', inputWidth: 100, value: orderTargetDate
      } : {
        type: 'calendar', readonly: true, name: 'order_target_date', label: 'Target Date', enableTodayButton: true, dateFormat: "%Y-%m-%d", calendarPosition: "right", inputWidth: 100, value: orderTargetDate
      };

      const keteranagnInput = ['view', 'cancom'].includes(MODE) ? {
        type: 'input', readonly: true, name: 'keterangan', label: 'Keterangan', inputWidth: 500, value: options.keterangan
      } : {
        type: 'input', readonly: false, name: 'keterangan', label: 'Keterangan', inputWidth: 500, value: options.keterangan
      };

      const firstBlock = {
        type: 'block',
        list: [
          { type: 'input', readonly: true, name: 'subplant', label: 'Subplant', inputWidth: 100, value: options.subplant },
          targetDateInput
          
        ]
      };
      const secondBlock = {
        type: 'block',
        list: [customerInput,
          keteranagnInput 
        ]
      };

      const infoFormConfig = [
        { type: 'settings', position: 'label-left', labelWidth: 80},
        firstBlock,
        { type: 'newcolumn' },
        secondBlock
      ];
      
      if (['edit', 'view', 'cancom'].includes(MODE)) {
        firstBlock.list.push({
          type: 'hidden',
          name: 'id',
          value: options.id
        });
        firstBlock.list.push({
          type: 'input',
          readonly: true,
          name: 'create_user',
          label: 'Dibuat Oleh',
          inputWidth: 100,
          value: options.createUser
        });
        secondBlock.list.push({
          type: 'input',
          readonly: true,
          name: 'create_date',
          label: 'Dibuat Pada',
          inputWidth: 250,
          value: options.createDate
        });
        firstBlock.list.push({
          type: 'hidden',
          name: 'ordertargetdate',
          value: orderTargetDate
        });
      } 
      infoForm = detailsLayout.cells(CELL_DETAILS_INFO).attachForm(infoFormConfig);

      // setup grid + toolbar
      const toolbarConfig = {
        iconset: 'awesome',
        items: [
          {
            type: 'button',
            id: 'add_items',
            text: 'Tambah Item',
            img: 'fa fa-plus',
            imgdis: 'fa fa-plus',
            enabled: false
          },
          {type: 'separator'},
          {
            type: 'button',
            id: 'remove_item',
            text: 'Hapus Item',
            img: 'fa fa-trash',
            imgdis: 'fa fa-trash',
            enabled: false
          },
          {
            type: 'button',
            id: 'undo_item',
            text: 'Batalkan Hapus Item',
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
      const toolbar = detailsLayout.cells(CELL_DETAILS_ITEMS).attachToolbar(toolbarConfig);
      if (['edit', 'create'].includes(MODE)) {
        toolbar.enableItem('add_items');
        toolbar.hideItem('print');
        toolbar.hideItem('export_csv');
        toolbar.hideItem('export_pdf');
      }
      if (['view', 'cancom'].includes(MODE)) {
        toolbar.enableItem('print');
        toolbar.enableItem('export_csv');
        toolbar.enableItem('export_pdf');
      }

      toolbar.attachEvent('onClick', id => {
        switch (id) {
          case 'add_items': {
            const subplant = infoForm.getItemValue('subplant');
            ItemsForBlocking.openAvailableItemsWindow(windows, subplant, win)
              .then(result => {
                if (!Array.isArray(result)) {
                  return;
                }
                if (result.length === 0) {
                  return;
                }
                result.forEach(item => {
                  const targetRowId = item.pallet_no;
                  if (!grid.doesRowExist(targetRowId)) {
                      grid.addRow(targetRowId, '');
                      grid.setRowData(targetRowId, {
                      pallet_no: item.pallet_no,
                      order_status: 'O',
                      motif_name: item.motif_name,
                      quality: item.quality,
                      size: item.size,
                      shading: item.shading,
                      qty: item.qty,
                      item_action: 'A'
                    });
                  }
                });
                // make sure that the counters and footer are updated accordingly.
                grid.resetCounter(0);
                grid.callEvent('onGridReconstructed', []);
                if (validateDetailsForm(grid)) {
                  actionForm.enableItem('save');
                }
                kalkulasiRimpil(grid);
              });
            break;
          }
          case 'remove_item': {
            // remove item: mark for deletion if item is existing, remove otherwise.
            const selectedRowId = grid.getSelectedRowId();
            if (!selectedRowId) {
              return;
            }
            const isExistingItem = grid.cells(selectedRowId, grid.getColIndexById('item_action')).getValue() === 'E';
            if (isExistingItem) {
              grid.cells(selectedRowId, grid.getColIndexById('item_action')).setValue('D');
              toolbar.enableItem('undo_item');
            } else {
              grid.deleteRow(selectedRowId);
            }    
            toolbar.disableItem(id);
            if (validateDetailsForm(grid)) {
              actionForm.enableItem('save');
            } else {
              actionForm.disableItem('save');
            }
            kalkulasiRimpil(grid)
            break;
          }
          case 'undo_item': {
            const selectedRowId = grid.getSelectedRowId();
            if (!selectedRowId) {
              return;
            }
            const isExistingItem = grid.cells(selectedRowId, grid.getColIndexById('item_action')).getValue() === 'D';
            if (isExistingItem) {
              grid.cells(selectedRowId, grid.getColIndexById('item_action')).setValue('E');
            }
            toolbar.enableItem('remove_item');
            toolbar.disableItem(id);

            if (validateDetailsForm(grid)) {
              actionForm.enableItem('save');
            } else {
              actionForm.disableItem('save');
            }
            kalkulasiRimpil(grid)
            break;
          }
          case 'print': {
            const details = {
              id: infoForm.getItemValue('id'),
              subplant: infoForm.getItemValue('subplant'),
              customer: infoForm.getItemValue('customer'),
              orderTargetDate: infoForm.getItemValue('ordertargetdate'),
              keterangan: infoForm.getItemValue('keterangan'),
              createUser: infoForm.getItemValue('create_user') || null,
              createDate: infoForm.getItemValue('create_date') || null
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
              customer: infoForm.getItemValue('customer'),
              orderTargetDate: infoForm.getItemValue('ordertargetdate'),
              keterangan: infoForm.getItemValue('keterangan'),
              createUser: infoForm.getItemValue('create_user') || null,
              createDate: infoForm.getItemValue('create_date') || null
            };
            win.progressOn();
            const filename = `Detail Block Quantity - ${details.id}` + '.pdf';
            exportDetailsAsPdf(details, grid)
              .download(filename);
            win.progressOff();
            break;
          }
        }
      });
      const grid = detailsLayout.cells(CELL_DETAILS_ITEMS).attachGrid();
      grid.setHeader('NO.,NO. PLT.,STATUS,MOTIF NAME,QUALITY,SIZE,SHADE,RIMPIL,QTY,AKSI');
      grid.setColumnIds('no,pallet_no,order_status,motif_name,quality,size,shading,isrimpil,qty,item_action');
      grid.setColSorting('na,str,str,str,str,str,str,int,int,str,int,na');
      grid.setColAlign('right,left,left,left,center,left,left,center,right,left');
      grid.setColTypes('cntr,rotxt,orderStatus,rotxt,rotxt,rotxt,rotxt,rotxt,ron,ro');
      grid.setInitWidths('40,140,80,*,80,50,60,80,120,10');
      grid.attachFooter(['&nbsp;', 'Total', COLUMN_SPAN, COLUMN_SPAN, COLUMN_SPAN, COLUMN_SPAN, COLUMN_SPAN, COLUMN_SPAN, gridUtils.reducers.STATISTICS_TOTAL, '&nbsp;'], ['', gridUtils.styles.TEXT_RIGHT_ALIGN + gridUtils.styles.TEXT_BOLD, '', '', '', '', '', gridUtils.styles.TEXT_RIGHT_ALIGN + gridUtils.styles.TEXT_BOLD], '');
      grid.setColumnHidden(grid.getColIndexById('item_action'), true);
      if (MODE === 'create') {
        grid.setColumnHidden(grid.getColIndexById('order_status'), true);
      }
      grid.setNumberFormat("0,000", grid.getColIndexById('qty'), ",", ".");
      grid.attachEvent('onCellChanged', (rowId, colIdx, newValue) => {
        if (colIdx === grid.getColIndexById('item_action')) {
          switch (newValue) {
            case 'A': // add
              grid.setRowColor(rowId, 'lime');
              break;
            case 'E': // delete
              grid.setRowColor(rowId, 'yellow');
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
          const itemAction = grid.cells(rowId, grid.getColIndexById('item_action')).getValue();
          if (itemAction === 'D') {
            toolbar.disableItem('remove_item');
            toolbar.enableItem('undo_item');
          } else {
            toolbar.enableItem('remove_item');
            toolbar.disableItem('undo_item');
          }
        }
      });
      grid.init();

      // load initial data
      if (['edit', 'view', 'cancom'].includes(MODE)) {
        // preload the data
        win.progressOn();
        fetchRequestDetails(options.id)
          .then(result => {
            return result.map(item => Object.assign(item, {
              id: item.pallet_no,
              item_action: 'E'
            }))
          })
          .then(items => {
            win.progressOff();
            grid.clearAll();
            grid.parse(items, 'js');
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
      } else if (MODE === 'cancom') {
        buttons.unshift({ type: 'newcolumn' });
        buttons.unshift({type: 'button', name: 'cancel', value: 'Batalkan Block', className: 'btn-danger'});
        buttons.unshift({ type: 'newcolumn' });
        buttons.unshift({type: 'button', name: 'complete', value: 'Block Selesai', className: 'btn-success'});
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
            // collect items going to be added
            const itemsToAdd = [];
            grid.forEachRow(rowId => {
              const itemAction = grid.cells(rowId, grid.getColIndexById('item_action')).getValue();
              if (itemAction !== 'D') {
                itemsToAdd.push(grid.getRowData(rowId));
              }  
            });
            // generate output text.
            let text = '';
            if (itemsToAdd.length > 0) {
              text += `${itemsToAdd.length} pallet berikut akan ditambahkan dalam block quantity :<br/><ol>`;
              itemsToAdd.forEach(item => {
                text += `<li>${item.pallet_no} [${item.motif_name} - ${item.quality} - ${item.size} - ${item.shading}] : ${item.qty} m<sup>2</sup></li>`;
              });
              text += '</ol><br/>';
            }

            text += 'Apakah Anda yakin?';

            dhtmlx.confirm({
              title: MODE === 'create' ? 'Konfirmasi Block Quantity' : 'Konfirmasi Perubahan Block Quantity',
              text: text,
              width: "600px",
              callback: confirmed => {
                if (!confirmed) {
                  return;
                }

                const subplant = infoForm.getItemValue('subplant');
                const customer = infoForm.getItemValue('customer');
                const order_target_date = infoForm.getItemValue('order_target_date');
                const keterangan = infoForm.getItemValue('keterangan');
                win.progressOn();
                if (MODE === 'create') {
                  createRequest(subplant, customer, order_target_date, keterangan, itemsToAdd.map(item => item.pallet_no), itemsToAdd.map(item => item.qty)
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
                } else { // edit
                  const orderId = infoForm.getItemValue('id');
                  updateRequestDetails(orderId, subplant, customer, order_target_date, keterangan, itemsToAdd.map(item => item.pallet_no), itemsToAdd.map(item => item.qty)
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
          case 'complete': {
            const orderId = infoForm.getItemValue('id');
            dhtmlx.prompt(windows, {
              title: 'Konfirmasi Block Quantity Selesai',
              message: `Isi sales order terkait ${orderId}:`
            })
            .then(result => {
              if (!result) {
                return;
              }
              win.progressOn();
              completeRequest(orderId, result)
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
            });
            break;
          }
          case 'cancel': {
            const orderId = infoForm.getItemValue('id');
            dhtmlx.prompt(windows, {
              title: 'Konfirmasi Pembatalan Block Quantity',
              message: `Isi alasan pembatalan ${orderId}:`
            })
            .then(result => {
              if (!result) {
                return;
              }
              win.progressOn();
              cancelRequest(orderId, result)
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
            });
            break;
          }
        }
      });
    }

    function validateDetailsForm(grid) {
      let itemsCount = 0;
      grid.forEachRow(rowId => {
        const action = grid.cells(rowId, grid.getColIndexById('item_action')).getValue();
        if (action === 'E' || action === 'A') {
          itemsCount++;
        }
      });

      return itemsCount > 0;
    }

    function kalkulasiRimpil(grid) {
      var rimpil = {};
      grid.forEachRow(rowId => {
        const action = grid.cells(rowId, grid.getColIndexById('item_action')).getValue();
        const motif_name = grid.cells(rowId, grid.getColIndexById('motif_name')).getValue();
        const size = grid.cells(rowId, grid.getColIndexById('size')).getValue();
        const shading = grid.cells(rowId, grid.getColIndexById('shading')).getValue();
        const qty = grid.cells(rowId, grid.getColIndexById('qty')).getValue();
        if (action === 'E' || action === 'A') {
          if(typeof rimpil[motif_name] != "undefined") {
            if(typeof rimpil[motif_name][size] != "undefined") {
              if(typeof rimpil[motif_name][size][shading] != "undefined") {
                rimpil[motif_name][size][shading] += qty;
              } else {
                rimpil[motif_name][size][shading] = qty;
              }    
            } else {
              rimpil[motif_name][size] = {};
              rimpil[motif_name][size][shading] = qty;
            }
          } else {
            rimpil[motif_name] = {};
            rimpil[motif_name][size] = {};
            rimpil[motif_name][size][shading] = qty;
          }          
        }
      });
      grid.forEachRow(rowId => {
        const motif_name = grid.cells(rowId, grid.getColIndexById('motif_name')).getValue();
        const size = grid.cells(rowId, grid.getColIndexById('size')).getValue();
        const shading = grid.cells(rowId, grid.getColIndexById('shading')).getValue();
        if(rimpil[motif_name][size][shading] < 100) {
          grid.cells(rowId, grid.getColIndexById('isrimpil')).setValue("YA");
        } else {
          grid.cells(rowId, grid.getColIndexById('isrimpil')).setValue("TIDAK");
        }            
      });
    }

    function exportDetailsAsPdf(details, grid) {
      const timestamp = new Date();
      const generatedAt = `Dibuat oleh [${USERID}] pada ${timestamp.toLocaleString(gridUtils.DEFAULT_LOCALE, gridUtils.DEFAULT_LOCALE_DATETIME_OPTIONS)}`;
      const colWidths = '40,120,80,*,80,50,60,80,120,10';
      const title = `Detail Block Quantity - ${details.id}`;

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
              { style: 'strong', text: 'Target Date' },
              { style: 'strong', text: 'Di Buat Oleh' }
            ]
          },
          {
            alignment: 'left',
            width: '*',
            margin: [5, 0],
            stack: [
              { style: 'strong', text: details.subplant },
              { style: 'strong', text: details.orderTargetDate },
              { style: 'strong', text: details.createUser }
            ]
          },
          {
            alignment: 'right',
            width: 'auto',
            stack: [
              { style: 'strong', text: 'Customer' },
              { style: 'strong', text: 'Keterangan' },
              { style: 'strong', text: 'Dibuat Pada' }
            ]
          },
          {
            alignment: 'left',
            width: '*',
            margin: [5, 0],
            stack: [
              { style: 'strong', text: details.customer },
              { style: 'strong', text: details.keterangan },
              { style: 'strong', text: details.createDate }
            ]
          },
        ]
      };

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

    function fetchRequestDetails(orderId) {
      return WMSApi.location.getBlockQuantityDetails(orderId);
    }

    function createRequest(requestedSubplant, customer, orderTargetDate, keterangan, palletNoS, qtyS) {
      return WMSApi.location.createBlockQuantityRequests(requestedSubplant, customer, orderTargetDate, keterangan, palletNoS, qtyS);
    }

    function completeRequest(orderId, keterangan = null) {
      return WMSApi.location.completeBlockQuantityRequests(orderId, keterangan);
    }    

    function cancelRequest(orderId, reason = null) {
      return WMSApi.location.cancelBlockQuantityRequests(orderId, reason);
    }

    function updateRequestDetails(orderId, requestedSubplant, customer, orderTargetDate, keterangan, palletNoS, qtyS) {
      return WMSApi.location.updateBlockQuantityRequests(orderId, requestedSubplant, customer, orderTargetDate, keterangan, palletNoS, qtyS);
    }

    function upsertRequestAtRootGrid(updatedRequest) {
      const grid = rootLayout.cells(CELL_REQUEST_LIST).getAttachedObject();
      const rowId = updatedRequest.order_id;
      if (!grid.doesRowExist(rowId)) {
        grid.addRow(rowId, '');
      } else {
        grid.deleteRow(rowId);
        grid.addRow(rowId, '');
      }
      grid.setRowData(rowId, updatedRequest);
      // make sure the counter and footer is recalculated
      grid.resetCounter(0);
      grid.callEvent('onGridReconstructed', []);
    }

    function showCustomer(infoForm) {
      const window_customer = windows.createWindow("w1", 0, 0, 600, 300);
      window_customer.centerOnScreen();
      window_customer.setText('Daftar Customer');
      window_customer.button("park").hide();
      window_customer.setModal(true);
      window_customer.button("minmax1").hide();

      const FILTERS = gridUtils.headerFilters;
      const grid_customer = window_customer.attachGrid();
      grid_customer.setHeader('NAMA CUSTOMER', null, ['']);
      grid_customer.setColTypes('ro');
      grid_customer.setColumnIds('customer');
      grid_customer.setInitWidths('*');
      grid_customer.setColumnMinWidth('550');
      grid_customer.attachHeader([FILTERS.TEXT]);
      grid_customer.setColAlign('left');
      grid_customer.setColSorting('str');
      grid_customer.attachEvent("onXLS", function () { window_customer.progressOn(); });
      grid_customer.attachEvent("onXLE", function () { window_customer.progressOff() });
      grid_customer.init();
      grid_customer.parse(kustomer_js.data, "js");
      grid_customer.attachEvent("onRowDblClicked", function (rowId) {
        const kustomer = grid_customer.cells(rowId, grid_customer.getColIndexById('customer')).getValue();
        var enc = kustomer.replace(/&amp;/g, '&');
        enc = enc.replace(/&lt;/g, '<');
        enc = enc.replace(/&gt;/g, '>');
        enc = enc.replace(/&quot;/g, '"');
        enc = enc.replace(/&apos;/g, "'");
        enc = enc.replace(/&nbsp;/g, '');
        enc = enc.replace(/br/g, 'BR');
        infoForm.setItemValue('customer', enc);
        window_customer.close();
      });
    }
  </script>
</head>
<body onload="doOnLoad()">

</body>
</html>
