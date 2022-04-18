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
    global.PalletsForBlocking = factory(global.WMSApi, global.gridUtils, global.moment)
  }
}(this, function (WMSApi, gridUtils, moment) {
  function fetchPalletData(subplant, productionDateFrom, productionDateTo, line, motifName, shift, quality = null) {
    return WMSApi.qa.fetchPalletsAvailableForBlocking(subplant, productionDateFrom, productionDateTo, line, motifName, shift, quality)
  }

  // TODO check how to fill this in from the service.
  const LINE_OPTIONS = [1, 2, 3, 4];
  const SHIFT_OPTIONS = [1, 2, 3];

  /**
   * Get pallets available for blocking
   * @param {dhtmlXWindows} windows
   * @param {string|Array} subplant
   * @param {string|Array} quality
   * @param {dhtmlXWindowsCell} [parentWin]
   * @return Promise<Array> list of selected pallets. will be empty if no pallet is selected.
   */
  let windowId = null;
  const selectedPallets = new Map(); // contains pallet numbers of checked pallets (pallet_no => true)
  function openAvailablePalletsWindow(windows, subplant, quality, parentWin = null) {
    selectedPallets.clear();
    return new Promise((resolve, reject) => {
      // setup head
      const win = windows.createWindow({
        id: 'pallets_for_blocking',
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
      win.setText('Daftar Palet untuk Blokir');
      windowId = win.getId();

      // setup layout
      const CELL_FILTER = 'a';
      const CELL_PALLETS = 'b';
      const CELL_ACTIONS = 'c';
      const layout = win.attachLayout({
        pattern: '3E',
        cells: [
          {id: CELL_FILTER, height: 80, fix_size: true, header: false},
          {id: CELL_PALLETS, fix_size: true, header: false},
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
      const qualityField = Array.isArray(quality) ? {
        type: 'combo',
        name: 'quality',
        label: 'Kualitas',
        readonly: true,
        offsetLeft: 20,
        inputWidth: 80,
        options: [ALL].concat(quality.map(q => ({text: q, value: q})))
      } : {
        type: 'input',
        name: 'quality',
        label: 'Kualitas',
        readonly: true,
        offsetLeft: 20,
        inputWidth: 80,
        value: quality
      };
      const filterFormConfig = [
        {type: "settings", position: "label-left", labelWidth: 50, inputWidth: 160},
        subplantField,
        {type: 'newcolumn'},
        {
          type: 'calendar',
          offsetLeft: 20,
          name: 'production_date_from',
          label: 'Tgl. Prod. Dari',
          enableTodayButton: true,
          required: true,
          dateFormat: "%Y-%m-%d",
          calendarPosition: "right",
          inputWidth: 100,
          value: moment().startOf('month').format('YYYY-MM-DD')
        },
        {type: 'newcolumn'},
        {
          type: 'calendar',
          offsetLeft: 20,
          name: 'production_date_to',
          label: 'Tgl. Prod. Hingga',
          enableTodayButton: true,
          required: true,
          readonly: true,
          dateFormat: "%Y-%m-%d",
          calendarPosition: "right",
          inputWidth: 100,
          value: moment().format('YYYY-MM-DD')
        },
        {type: 'newcolumn'},
        qualityField,
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
          type: 'combo',
          name: 'line',
          label: 'Line',
          options: [ALL].concat(LINE_OPTIONS.map(line => ({text: line, value: line}))),
          readonly: true,
          offsetLeft: 20,
          inputWidth: 80
        },
        {type: 'newcolumn'},
        {
          type: 'combo',
          name: 'creator_shift',
          label: 'Shift',
          options: [ALL].concat(SHIFT_OPTIONS.map(shift => ({text: shift, value: shift}))),
          readonly: true,
          offsetLeft: 20,
          inputWidth: 80
        },
        {type: 'newcolumn'},
        {type: 'button', offsetLeft: 20, name: 'getdata', value: 'Dapatkan Data!'}
      ];
      const filterForm = layout.cells(CELL_FILTER).attachForm(filterFormConfig);
      filterForm.attachEvent('onChange', (name, value) => {
        switch (name) {
          case 'production_date_from':
            filterForm.getCalendar('production_date_to').setSensitiveRange(value, moment().format('YYYY-MM-DD'));
            return true;
          case 'production_date_to':
            filterForm.getCalendar('production_date_from').setSensitiveRange(null, value);
            return true;
        }
      });
      filterForm.attachEvent('onButtonClick', id => {
        if (id === 'getdata') {
          const subplant = filterForm.getItemValue('subplant');
          const productionDateFrom = filterForm.getItemValue('production_date_from');
          const productionDateTo = filterForm.getItemValue('production_date_to');
          const line = filterForm.getItemValue('line');
          const shift = filterForm.getItemValue('creator_shift');
          const quality = filterForm.getItemValue('quality');
          const motifName = filterForm.getItemValue('motif');

          win.progressOn();
          fetchPalletData(subplant, productionDateFrom, productionDateTo, line, motifName, shift, quality)
            .then(result => ({
              data: result.map(pallet => Object.assign(pallet, {
                id: pallet.pallet_no,
                is_checked: selectedPallets.has(pallet.pallet_no)
              }))
            }))
            .then(data => {
              // clear the map, and reset using present elements
              selectedPallets.clear();
              data.data.filter(pallet => pallet.is_checked).forEach(pallet => {
                selectedPallets.set(pallet.pallet_no, true);
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
      const grid = layout.cells(CELL_PALLETS).attachGrid();
      grid.setImagesPath('../assets/libs/dhtmlx/imgs/'); // TODO
      grid.setHeader(
        '&nbsp;,NO. PLT.,DIM.,QLTY.,KD. MOTIF,MOTIF,TGL. PROD.,LINE,SHIFT,REGU,SZ.,SHADE.,QTY.',
        null,
        [
          '', '', '', '', '', '', '', TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, '', '', '', TEXT_RIGHT_ALIGN
        ]);
      grid.setColumnIds('is_checked,pallet_no,motif_dimension,quality,motif_id,motif_name,production_date,line,creator_shift,creator_group,size,shading,current_quantity');
      grid.setColTypes('ch,rotxt,rotxt,rotxt,rotxt,rotxt,ro_date,ron,ron,rotxt,rotxt,rotxt,ron');
      grid.setInitWidths('70,130,50,50,80,*,80,50,50,50,50,60,120');
      grid.setColAlign('center,left,left,left,left,left,left,right,right,left,left,left,right');
      grid.setColumnHidden(grid.getColIndexById('motif_id'), true);
      grid.setNumberFormat('0,000', grid.getColIndexById('current_quantity'), ".", ",");

      grid.attachEvent('onCheck', (rowId, colIdx, isChecked) => {
        const palletNo = rowId;
        if (isChecked) {
          if (!selectedPallets.has(palletNo)) {
            selectedPallets.set(palletNo, true)
          }
        } else {
          if (selectedPallets.has(palletNo)) {
            selectedPallets.delete(palletNo);
          }
        }

        if (selectedPallets.size > 0) {
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
          const palletsToAdd = [];
          selectedPallets.forEach((_, palletNo) => {
            if (!grid.doesRowExist(palletNo)) {
              console.warn(`Missing data for ${palletNo}`);
              return;
            }
            palletsToAdd.push(grid.getRowData(palletNo));
          });
          resolve(palletsToAdd);
          win.close();
        } else if (btnId === 'cancel') {
          resolve([]);
          win.close();
        }
      })
    });
  }

  return {openAvailablePalletsWindow, fetchPalletData, windowId}
}));
