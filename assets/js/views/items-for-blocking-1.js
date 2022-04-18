(function (global, factory) {
  if (typeof define === 'function' && define.amd) {
    define(['WMSApi', 'gridUtils', 'DateUtils', 'moment'], factory);
  } else if (typeof module === 'object' && module.exports) {
    module.exports = factory(require('assets/js/WMSApi'), require('gridUtils'), require('DateUtils'), require('moment'))
  } else {
    if (!global.hasOwnProperty('WMSApi')) {
      throw Error('WMSApi is not loaded!')
    }
    if (!global.hasOwnProperty('gridUtils')) {
      throw Error('gridUtils is not loaded!')
    }
    if (!global.hasOwnProperty('moment')) {
      throw Error('moment is not loaded!')
    }
    global.ItemsForBlocking = factory(global.WMSApi, global.gridUtils, global.moment)
  }
}(this, function (WMSApi, gridUtils, moment) {
  function fetchItemData(subplant, quality, size, shading, motifName, lokasi) {
    return WMSApi.location.fetchItemsAvailableForBlocking(subplant, quality, size, shading, motifName, lokasi)
  }

  // TODO check how to fill this in from the service.
  const SIZE_OPTIONS = ['US', 'S', 'N', 'X', 'L', 'XL', 'LL', 'OV', 'OL', 'X1', 'KK'];
  const QUALITY_OPTIONS = ['EXP', 'ECO'];

  /**
   * Get items available for blocking
   * @param {dhtmlXWindows} windows
   * @param {string|Array} subplant
   * @param {dhtmlXWindowsCell} [parentWin]
   * @return Promise<Array> list of selected items. will be empty if no item is selected.
   */
  let windowId = null;
  const selectedItems = new Map(); // contains item numbers of checked items
  function openAvailableItemsWindow(windows, subplant, parentWin = null) {
    selectedItems.clear();
    return new Promise((resolve, reject) => {
      // setup head
      const win = windows.createWindow({
        id: 'items_for_blocking',
        width: 700,
        height: 700,
        center: true,
        park: false,
        modal: true,
        onClose: () => {
          windowId = null;
          if (parentWin) {
            parentWin.setModal(true);
          }
        }
      });
      win.button('park').hide();
      win.maximize();
      win.setText('Daftar Pallet untuk di Blokir Quantity');
      windowId = win.getId();

      // setup layout
      const CELL_FILTER = 'a';
      const CELL_ITEMS = 'b';
      const CELL_ACTIONS = 'c';
      const layout = win.attachLayout({
        pattern: '3E',
        cells: [
          {id: CELL_FILTER, height: 80, fix_size: true, header: false},
          {id: CELL_ITEMS, fix_size: true, header: false},
          {id: CELL_ACTIONS, height: 60, fix_size: true, header: false}
        ]
      });

      // setup filter form
      const ALL = {text: 'Semua', value: 'all', selected: true};
      const subplantField = Array.isArray(subplant) ? {
        type: 'combo',
        name: 'subplant',
        label: 'Subplant',
        readonly: true,
        offsetLeft: 20,
        inputWidth: 80,
        options: subplant.length > 1 ? [ALL].concat(subplant.map(subp => ({text: subp, value: subp}))) : subplant
      } : {
        type: 'input',
        name: 'subplant',
        label: 'Subplant',
        readonly: true,
        offsetLeft: 20,
        inputWidth: 80,
        value: subplant
      };
      const filterFormConfig = [
        {type: "settings", position: "label-left", labelWidth: 50, inputWidth: 160},
        subplantField,
        {type: 'newcolumn'},
        {
          type: 'combo',
          name: 'quality',
          label: 'Quality',
          options: [ALL].concat(QUALITY_OPTIONS.map(quality => ({text: quality, value: quality}))),
          readonly: true,
          offsetLeft: 20,
          inputWidth: 80
        },
        {type: 'newcolumn'},
        {
          type: 'combo',
          name: 'size',
          label: 'Size',
          options: [ALL].concat(SIZE_OPTIONS.map(size => ({text: size, value: size}))),
          readonly: true,
          offsetLeft: 20,
          inputWidth: 80
        },
        {type: 'newcolumn'},
        {
          type: 'input',
          name: 'shading',
          label: 'Shade',
          offsetLeft: 20,
          inputWidth: 80,
        },
        {type: 'newcolumn'},
        {
          type: 'input',
          name: 'motif',
          label: 'Motif',
          offsetLeft: 20,
          inputWidth: 150,
        },
        {type: 'newcolumn'},
        {
          type: 'input',
          name: 'lokasi',
          label: 'Lokasi',
          offsetLeft: 20,
          inputWidth: 150,
        },
        {type: 'newcolumn'},
        {type: 'button', offsetLeft: 20, name: 'getdata', value: 'Dapatkan Data!'}
      ];
      const filterForm = layout.cells(CELL_FILTER).attachForm(filterFormConfig);
      filterForm.attachEvent('onButtonClick', id => {
        if (id === 'getdata') {
          const subplant = filterForm.getItemValue('subplant');
          const quality = filterForm.getItemValue('quality');
          const size = filterForm.getItemValue('size');
          const shading = filterForm.getItemValue('shading');
          const motifName = filterForm.getItemValue('motif');
          const lokasi = filterForm.getItemValue('lokasi');

          win.progressOn();
          fetchItemData(subplant, quality, size, shading, motifName, lokasi)
            .then(result => ({
              data: result.map(pallet => Object.assign(pallet, {
                id: pallet.pallet_no,
                is_checked: selectedItems.has(pallet.pallet_no)
              }))
            }))
            .then(data => {
              // clear the map, and reset using present elements
              selectedItems.clear();
              data.data.filter(pallet => pallet.is_checked).forEach(pallet => {
                selectedItems.set(pallet.pallet_no, true);
              });

              grid.clearAll();
              grid.parse(data, 'js');

              win.progressOff();
            })
            .catch(error => {
              win.progressOff();
              handleApiError(error, win);
            });
        }
      });

      // setup grid
      const FILTERS = gridUtils.headerFilters;
      const grid = layout.cells(CELL_ITEMS).attachGrid();
      grid.setImagesPath('../assets/libs/dhtmlx/imgs/'); // TODO
      grid.setHeader('&nbsp;,AREA,BARIS,NO. PLT.,MOTIF NAME,TGL PRODUKSI,QUALITY,SIZE,SHADE,QTY',null,
        ['', '', '', '', '', '', '', '', TEXT_RIGHT_ALIGN]);
      grid.setColumnIds('is_checked,area,baris,pallet_no,motif_name,tanggal,quality,size,shading,qty');
      grid.setColTypes('ch,rotxt,ron,rotxt,rotxt,rotxt,rotxt,rotxt,ron,ron');
      grid.setInitWidths('40,200,50,140,*,100,80,50,60,120');
      grid.setColAlign('center,left,right,left,left,center,center,left,left,right');
      grid.setNumberFormat('0,000', grid.getColIndexById('qty'), ".", ",");
      grid.attachHeader(['&nbsp;', FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT]);
      grid.attachEvent('onCheck', (rowId, colIdx, isChecked) => {
        if (isChecked) {
          if (!selectedItems.has(rowId)) {
            selectedItems.set(rowId, true)
          }
        } else {
          if (selectedItems.has(rowId)) {
            selectedItems.delete(rowId);
          }
        }

        if (selectedItems.size > 0) {
          actionForm.enableItem('add');
        } else {
          actionForm.disableItem('add');
        }
      });
      grid.enableSmartRendering(true, 100);
      grid.init();

      // setup action buttons
      const actionFormConfig = [
        {type: 'button', name: 'add', value: 'Tambah'},
        {type: 'newcolumn'},
        {type: 'button', name: 'cancel', value: 'Batal'}
      ];
      const actionForm = layout.cells(CELL_ACTIONS).attachForm(actionFormConfig);
      actionForm.disableItem('add');

      actionForm.attachEvent('onButtonClick', btnId => {
        if (btnId === 'add') {
          // collect all pallet data
          const itemsToAdd = [];
          selectedItems.forEach((_, barisNO) => {
            if (!grid.doesRowExist(barisNO)) {
              console.warn(`Missing data for ${barisNO}`);
              return;
            }
            itemsToAdd.push(grid.getRowData(barisNO));
          });
          resolve(itemsToAdd);
          win.close();
        } else if (btnId === 'cancel') {
          resolve([]);
          win.close();
        }
      })
    });
  }

  return {openAvailableItemsWindow, fetchItemData, windowId}
}));
