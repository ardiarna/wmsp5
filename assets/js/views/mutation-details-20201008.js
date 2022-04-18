(function (global, factory) {
  if (typeof define === 'function' && define.amd) {
    define(['WMSApi', 'gridUtils', 'DateUtils', 'moment', 'handleApiError'], factory);
  } else if (typeof module === 'object' && module.exports) {
    module.exports = factory(require('WMSApi'), require('gridUtils'), require('DateUtils'), require('moment'), require('handleApiError'))
  } else {
    if (!global.hasOwnProperty('WMSApi')) {
      throw Error('WMSApi is not loaded!')
    }
    if (!global.hasOwnProperty('gridUtils')) {
      throw Error('gridUtils is not loaded!')
    }
    if (!global.hasOwnProperty('DateUtils')) {
      throw Error('DateUtils is not loaded!')
    }
    if (!global.hasOwnProperty('moment')) {
      throw Error('moment is not loaded!')
    }
    if (!global.hasOwnProperty('handleApiError')) {
      throw Error('handleApiError is not loaded!')
    }
    global.MutationDetails = factory(global.WMSApi, global.gridUtils, global.DateUtils, global.moment, global.handleApiError)
  }
}(this, function (WMSApi, gridUtils, DateUtils, moment, handleApiError) {
  const STYLES = gridUtils.styles;
  const FILTERS = gridUtils.headerFilters;

  const COLUMN_MAP = Object.freeze({
    initial_quantity: 'BEGIN',

    prod_initial_quantity: 'PROD',
    manual_initial_quantity: 'PLM',
    in_mut_quantity: 'MUT_IN',
    in_adjusted_quantity: 'ADJ_IN',
    in_downgrade_quantity: 'DWG_IN',
    in_quantity_total: 'TTL_IN',

    out_mut_quantity: 'MUT_OUT',
    out_adjusted_quantity: 'ADJ_OUT',
    returned_quantity: 'PROD_RET',
    broken_quantity: 'BROKEN',
    sales_in_progress_quantity: 'SALES_IN_PROGRESS',
    sales_confirmed_quantity: 'SALES_CONFIRMED',
    foc_quantity: 'FOC',
    sample_quantity: 'SMP',
    out_downgrade_quantity: 'DWG_OUT',
    out_quantity_total: 'TTL_OUT',

    final_quantity: 'END'
  });

  /**
   * Fetch mutation details data from the service.
   * @param {string} mutationType
   * @param {string|Array<string>} subplant
   * @param {Date|string} dateFrom
   * @param {Date|string} dateTo
   * @param {string|Array<string>} motifId
   * @return {Promise<Array>}
   */
  function fetchDetailsData(mutationType, subplant, dateFrom, dateTo, motifId) {
    return WMSApi.stock.fetchStockMutationSummaryDetails(mutationType, subplant, dateFrom, dateTo, motifId)
      .then(({records, motifs}) => records.map(record => Object.assign(record, {
        motif_name: motifs[record.motif_id].motif_name,
        motif_dimension: motifs[record.motif_id].motif_dimension,
        quality: motifs[record.motif_id].quality
      })))
  }

  let windowId = null;

  /**
   * Open a new mutation details window, for per motif and per mutation type details.
   * @param {dhtmlXWindows} windows
   * @param {string} mutationType
   * @param {string} subplant
   * @param {Date} dateFrom
   * @param {Date} dateTo
   * @param {string} motifId
   * @param {string} motifName
   * @param {string} colName
   */
  function openMutationDetailsWindow(windows, mutationType, subplant, dateFrom, dateTo, motifId, motifName, colName) {
    // setup window
    windowId = 'mut_details';
    const win = windows.createWindow(windowId, 0, 0, 700, 500);

    win.centerOnScreen();
    win.button('park').hide();
    win.setModal(true);

    let title;
    title = `(${DateUtils.toSqlDate(dateFrom)} - ${DateUtils.toSqlDate(dateTo)}) Detail Mutasi ${subplant} - ${colName} - ${motifName}`;
    win.setText(title);

    // setup toolbar
    const toolbar = win.attachToolbar({
      iconset: 'awesome',
      items: [
        {type: 'button', id: 'refresh', text: 'Segarkan', img: 'fa fa-refresh'},
        {type: 'button', id: 'export_csv', text: 'Ke CSV', img: 'fa fa-file-excel-o'},
        {type: 'spacer'},
        {type: 'text', id: 'timestamp'}
      ]
    });
    toolbar.attachEvent('onClick', itemId => {
      if (itemId === 'refresh') {
        win.progressOn();
        fetchDetailsData(mutationType, subplant, dateFrom, dateTo, motifId)
          .then(summaryDetails => processDetails(win, {
            data: summaryDetails,
            mutationType,
            subplant,
            motifName
          }))
          .catch(error => {
            win.progressOff();
            handleApiError(error);
          });
      } else if (itemId === 'export_csv') {
        gridUtils.downloadFilteredCSV(grid, title)
      }
    });

    // setup grid
    const grid = win.attachGrid();
    if (mutationType === COLUMN_MAP.initial_quantity) {
      grid.setHeader(
        'NO. PALET,SIZE,SHADE,QTY.',
        null,
        ['', '', '', STYLES.TEXT_RIGHT_ALIGN]
      );
      grid.setColTypes('ro,ro,ro,ron');
      grid.setColumnIds('pallet_no,size,shading,quantity');
      grid.setInitWidths('*,60,60,60,50');
      grid.attachHeader([FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.NUMERIC]);
      grid.setColAlign('left,left,left,right');
      grid.setColSorting('str,str,str,int');
      grid.attachFooter(['Total', gridUtils.spans.COLUMN, gridUtils.reducers.STATISTICS_COUNT, gridUtils.reducers.STATISTICS_TOTAL],
        [STYLES.TEXT_BOLD, '', STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN]);

      grid.setNumberFormat('0,000', grid.getColIndexById('quantity'), ',', '.');

    } else if (mutationType === COLUMN_MAP.final_quantity) {
      grid.setHeader(
        'NO. PALET,SIZE,SHADE,LOKASI,QTY.',
        null,
        ['', '', '', '', STYLES.TEXT_RIGHT_ALIGN]
      );
      grid.setColTypes('ro,ro,ro,ro,ron');
      grid.setColumnIds('pallet_no,size,shading,location_id,quantity');
      grid.setInitWidths('*,60,60,90,70');
      grid.attachHeader([FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.NUMERIC]);
      grid.setColAlign('left,left,left,left,right');
      grid.setColSorting('str,str,str,str,int');
      grid.attachFooter(['Total', gridUtils.spans.COLUMN, gridUtils.spans.COLUMN, gridUtils.reducers.STATISTICS_COUNT, gridUtils.reducers.STATISTICS_TOTAL],
        [STYLES.TEXT_BOLD, '', '', STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN]);

      grid.setNumberFormat('0,000', grid.getColIndexById('quantity'), ',', '.');
    } else if (mutationType === COLUMN_MAP.sales_in_progress_quantity) {
      grid.setHeader(
        'TGL.,NO. BA MUAT,QTY.',
        null,
        ['', '', STYLES.TEXT_RIGHT_ALIGN]
      );
      grid.setColTypes('ro_date,ro,ron');
      grid.setColumnIds('mutation_date,mutation_id,quantity');
      grid.setInitWidths('*,120,100');
      grid.attachHeader([FILTERS.TEXT, FILTERS.TEXT, FILTERS.NUMERIC]);
      grid.setColAlign('left,left,right');
      grid.setColSorting('str,str,int');
      grid.attachFooter(['Total', gridUtils.reducers.STATISTICS_COUNT, gridUtils.reducers.STATISTICS_TOTAL],
        [STYLES.TEXT_BOLD, STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN]);

      grid.setNumberFormat('0,000', grid.getColIndexById('quantity'), ',', '.');
    } else if (mutationType === COLUMN_MAP.sales_confirmed_quantity) {
      grid.setHeader(
        'TGL.,NO. BA MUAT,NO. SJ.,QTY.',
        null,
        ['', '', '', STYLES.TEXT_RIGHT_ALIGN]
      );
      grid.setColTypes('ro_date,ro,ro,ron');
      grid.setColumnIds('mutation_date,mutation_id,ref_txn_id,quantity');
      grid.setInitWidths('*,120,120,100');
      grid.attachHeader([FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.NUMERIC]);
      grid.setColAlign('left,left,left,right');
      grid.setColSorting('str,str,str,int');
      grid.attachFooter(['Total', gridUtils.spans.COLUMN, gridUtils.reducers.STATISTICS_COUNT, gridUtils.reducers.STATISTICS_TOTAL],
        [STYLES.TEXT_BOLD, '', STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN]);

      grid.setNumberFormat('0,000', grid.getColIndexById('quantity'), ',', '.');
    } else {
      grid.setHeader(
        'TGL.,NO. TXN.,NO. PALET,SIZE,SHADE,QTY.',
        null,
        ['', '', '', '', STYLES.TEXT_RIGHT_ALIGN]
      );
      grid.setColTypes('ro_ts,ro,ro,ro,ro,ron');
      grid.setColumnIds('mutation_time,mutation_id,pallet_no,size,shading,quantity');
      grid.setInitWidths('160,120,130,60,60,*');
      grid.attachHeader([FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.NUMERIC]);
      grid.setColAlign('left,left,left,left,left,right');
      grid.setColSorting('str,str,str,str,str,int');
      grid.attachFooter(['Total', gridUtils.spans.COLUMN, gridUtils.spans.COLUMN, gridUtils.spans.COLUMN, gridUtils.reducers.STATISTICS_COUNT, gridUtils.reducers.STATISTICS_TOTAL],
        [STYLES.TEXT_BOLD, '', '', '', STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN]);

      grid.setNumberFormat('0,000', grid.getColIndexById('quantity'), ',', '.');
    }

    grid.enableSmartRendering(true, 100);
    grid.init();

    win.progressOn();
    fetchDetailsData(mutationType, subplant, dateFrom, dateTo, motifId)
      .then(summaryDetails => processDetails(win, {
        data: summaryDetails,
        mutationType,
        subplant,
        motifName
      }))
      .catch(error => {
        win.progressOff();
        handleApiError(error);
      });
  }

  /**
   * Process the received details or display on the details window
   * @param {dhtmlXWindowsCell} win window object
   * @param details content of the details
   * @param {Array} details.data data returned from the service
   * @param {string} details.subplant selected subplant
   * @param {string} details.motifName selected motif name
   * @param {string} details.mutationType selected mutation type
   */
  function processDetails(win, details) {
    const grid = win.getAttachedObject();
    const toolbar = win.getAttachedToolbar();

    win.progressOff();
    gridUtils.clearAllGridFilters(grid);
    grid.clearAll();

    if (details.data.length === 0) {
      dhtmlx.message(`Tidak ada detail mutasi ${details.mutationType} untuk ${details.subplant} - ${details.motifName}!`);
      return;
    }
    const gridData = {
      data: details.data.map(detail => {
        const mutationType = details.mutationType;
        let id;
        if (mutationType === COLUMN_MAP.initial_quantity || mutationType === COLUMN_MAP.final_quantity) {
          id = detail.pallet_no;
        } else if (mutationType === COLUMN_MAP.sales_confirmed_quantity || mutationType === COLUMN_MAP.sales_in_progress_quantity) {
          id = `${detail.mutation_id}`;
        } else {
          id = `${detail.mutation_id}_${detail.pallet_no}_${detail.size}_${detail.shading}`;
        }
        return Object.assign(detail, { id: id });
      })
    };

    grid.parse(gridData, 'js');
    toolbar.setItemText('timestamp', moment().format());
  }

  /**
   * Open a new mutation details window, for aggregate motifs and subplants.
   * @param {dhtmlXWindows} windows
   * @param {string} mutationType
   * @param {Array<string>} subplants
   * @param {Date} dateFrom
   * @param {Date} dateTo
   * @param {Array<string>} motifIds
   * @param {string} colName
   */
  const existingAggregateDetailsData = [];
  function openAggregateMutationDetailsWindow(windows, mutationType, subplants, dateFrom, dateTo, motifIds, colName) {
    // setup fetch params
    const mutationTypeFetch = [COLUMN_MAP.sales_in_progress_quantity, COLUMN_MAP.sales_confirmed_quantity].includes(mutationType) ?
      mutationType + '_PALLET' :
      mutationType;

    // setup window
    windowId = 'mut_details';
    const win = windows.createWindow(windowId, 0, 0, 900, 500);
    win.attachEvent('onClose', () => {
      existingAggregateDetailsData.splice(0, existingAggregateDetailsData.length);
      return true;
    });

    win.centerOnScreen();
    win.maximize();
    win.button('park').hide();
    win.setModal(true);

    let baseTitle;
    baseTitle = `(${DateUtils.toSqlDate(dateFrom)} - ${DateUtils.toSqlDate(dateTo)}) Detail Mutasi - ${colName}`;
    if (subplants.length === 1) {
      baseTitle += ` - ${subplants[0]}`
    } else {
      baseTitle += ` - ${subplants[0][0]}`
    }
    win.setText(baseTitle);

    // setup toolbar
    let selectedMode = 'mode-details';
    const title = selectedMode === 'mode-summary' ? baseTitle + ' - Ringkasan' : baseTitle + ' - Detail';
    win.setText(title);
    const toolbar = win.attachToolbar({
      iconset: 'awesome',
      items: [
        {type: 'button', id: 'refresh', text: 'Segarkan', img: 'fa fa-refresh'},
        {type: 'button', id: 'export_csv', text: 'Ke CSV', img: 'fa fa-file-excel-o'},
        {type: 'separator'},
        {type: 'text', id: 'text-mode', text: 'Mode'},
        {type: 'buttonTwoState', id: 'mode-summary', text: 'Ringkasan Harian'},
        {type: 'buttonTwoState', id: 'mode-details', text: 'Detail'},
        {type: 'spacer'},
        {type: 'text', id: 'timestamp'}
      ]
    });
    toolbar.setItemState('mode-summary', selectedMode === 'mode-summary');
    toolbar.setItemState('mode-details', selectedMode === 'mode-details');
    if ([COLUMN_MAP.sales_confirmed_quantity, COLUMN_MAP.sales_in_progress_quantity].includes(mutationType)) {
      toolbar.setItemText('mode-details', 'Detail per Motif');
      toolbar.addButtonTwoState('mode-period', toolbar.getPosition('text-mode') + 1, 'Ringkasan per Periode');
      toolbar.addButtonTwoState('mode-details-pallet', toolbar.getPosition('mode-details'), 'Detail per Palet');
    } else if ([COLUMN_MAP.final_quantity, COLUMN_MAP.initial_quantity].includes(mutationType)) {
      toolbar.setItemText('mode-summary', 'Ringkasan per Motif');
    } else {
      toolbar.addButtonTwoState('mode-period', toolbar.getPosition('text-mode') + 1, 'Ringkasan per Periode');
    }

    toolbar.attachEvent('onClick', itemId => {
      if (itemId === 'refresh') {
        win.progressOn();
        fetchDetailsData(mutationTypeFetch, subplants, dateFrom, dateTo, motifIds)
          .then(summaryDetails => {
            existingAggregateDetailsData.splice(0, existingAggregateDetailsData.length); // clear 'cached' data
            existingAggregateDetailsData.push(...summaryDetails);
            return processAggregateDetails(selectedMode, {
              data: summaryDetails,
              mutationType,
              subplants
            });
          })
          .then(processedDetails => showAggregateDetails(win, selectedMode, processedDetails))
          .catch(error => {
            win.progressOff();
            handleApiError(error);
          });
      } else if (itemId === 'export_csv') {
        gridUtils.downloadFilteredCSV(grid, win.getText())
      }
    });
    toolbar.attachEvent('onBeforeStateChange', id => {
      return id !== selectedMode;
    });
    toolbar.attachEvent('onStateChange', (id, newState) => {
      if (newState === true) {
        selectedMode = id;
        // disable report for all subplant.
        toolbar.forEachItem(itemId => {
          const itemType = toolbar.getType(itemId);
          if (itemType === 'buttonTwoState' && itemId !== selectedMode) {
            toolbar.setItemState(itemId, false);
          }
        });

        win.progressOn();
        new Promise((resolve, reject) => {
          setTimeout(() => {
            try {
              resolve(processAggregateDetails(selectedMode, {
                data: existingAggregateDetailsData,
                subplants,
                mutationType
              }))
            } catch (e) {
              reject(e);
            }
          }, 0)
        }).then(processedDetails => showAggregateDetails(win, selectedMode, processedDetails))
          .then(() => {
            const title = baseTitle + ` - ${toolbar.getItemText(selectedMode)}`;
            win.setText(title);
          })
          .catch(error => {
            win.progressOff();
            handleApiError(error);
          })
      }
    });

    // setup grid
    const grid = win.attachGrid();
    if (mutationType === COLUMN_MAP.initial_quantity) {
      grid.setHeader(
        'SUBP.,KD. MOTIF,QLTY.,DIM.,MOTIF,NO. PALET,SIZE,SHADE,TTL. PLT.,QTY.',
        null,
        ['', '', '', '', '', '', '', '', STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_RIGHT_ALIGN]
      );
      grid.setColTypes('ro,ro,ro,ro,ro,ro,ro,ro,ron,ron');
      grid.setColumnIds('subplant,motif_id,quality,motif_dimension,motif_name,pallet_no,size,shading,pallet_count,quantity');
      grid.setInitWidths('60,0,60,60,*,120,60,60,60,60,60');
      grid.attachHeader([FILTERS.SELECT, '&nbsp;', FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.NUMERIC, FILTERS.NUMERIC]);
      grid.setColAlign('left,left,left,left,left,left,left,left,right,right');
      grid.setColSorting('str,str,str,str,str,str,str,str,int,int');
      grid.attachFooter(['&nbsp;', '&nbsp;', 'Total', gridUtils.spans.COLUMN, '&nbsp;', '&nbsp;', '&nbsp;', gridUtils.reducers.STATISTICS_COUNT, gridUtils.reducers.STATISTICS_TOTAL, gridUtils.reducers.STATISTICS_TOTAL],
        ['', '', STYLES.TEXT_BOLD, '', '', '', '', STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN]);

    } else if (mutationType === COLUMN_MAP.final_quantity) {
      grid.setHeader(
        'SUBP.,KD. MOTIF,QLTY.,DIM.,MOTIF,NO. PALET,SIZE,SHADE,LOKASI,TTL. PLT.,QTY.',
        null,
        ['', '', '', '', '', '', '', '', '', STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_RIGHT_ALIGN]
      );
      grid.setColTypes('ro,ro,ro,ro,ro,ro,ro,ro,ro,ron,ron');
      grid.setColumnIds('subplant,motif_id,quality,motif_dimension,motif_name,pallet_no,size,shading,location_id,pallet_count,quantity');
      grid.setInitWidths('60,0,60,60,*,120,60,60,90,70,70');
      grid.attachHeader([FILTERS.SELECT, '&nbsp;', FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.NUMERIC, FILTERS.NUMERIC]);
      grid.setColAlign('left,left,left,left,left,left,left,left,left,right,right');
      grid.setColSorting('str,str,str,str,str,str,str,str,str,int,int');
      grid.attachFooter(['&nbsp;', '&nbsp;', 'Total', gridUtils.spans.COLUMN, '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', gridUtils.reducers.STATISTICS_COUNT, gridUtils.reducers.STATISTICS_TOTAL, gridUtils.reducers.STATISTICS_TOTAL],
        ['', '', STYLES.TEXT_BOLD, '', '', '', '', '', STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN]);

    } else if (mutationType === COLUMN_MAP.sales_in_progress_quantity) {
      grid.setHeader(
        'SUBP.,KD. MOTIF,QLTY.,DIM.,MOTIF,TGL.,MUT.,NO. PALET,TTL. PLT.,NO. BA MUAT,QTY.',
        null,
        ['', '', '', '', '', '', '', '', '', STYLES.TEXT_RIGHT_ALIGN, '', STYLES.TEXT_RIGHT_ALIGN]
      );
      grid.setColTypes('ro,ro,ro,ro,ro,ro_date,ro,ron,ro,ro,ron');
      grid.setColumnIds('subplant,motif_id,quality,motif_dimension,motif_name,mutation_date,mutation_type,pallet_no,pallet_count,mutation_id,quantity');
      grid.setInitWidths('60,0,60,60,*,120,40,130,80,120,80');
      grid.attachHeader([FILTERS.SELECT, '&nbsp;', FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.NUMERIC, FILTERS.TEXT, FILTERS.NUMERIC]);
      grid.setColAlign('left,left,left,left,left,left,left,left,right,left,right');
      grid.setColSorting('str,str,str,str,str,str,str,str,int,str,int');
      grid.attachFooter(['&nbsp;', '&nbsp;', 'Total', gridUtils.spans.COLUMN, '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', gridUtils.reducers.STATISTICS_TOTAL, '&nbsp;', gridUtils.reducers.STATISTICS_TOTAL],
        ['', '', STYLES.TEXT_BOLD, '', '', '', STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN, '', STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN, '', STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN]);
    } else if (mutationType === COLUMN_MAP.sales_confirmed_quantity) {
      grid.setHeader(
        'SUBP.,KD. MOTIF,QLTY.,DIM.,MOTIF,LOKASI,TGL.,MUT.,NO. PALET,TTL. PLT.,NO. BA MUAT,NO. SJ.,QTY.',
        null,
        ['', '', '', '', '', '', '', '', '', '', '', STYLES.TEXT_RIGHT_ALIGN, '', '', STYLES.TEXT_RIGHT_ALIGN]
      );
      grid.setColTypes('ro,ro,ro,ro,ro,ro,ro_date,ro,ron,ro,ro,ro,ron');
      grid.setColumnIds('subplant,motif_id,quality,motif_dimension,motif_name,lokasi,mutation_date,mutation_type,pallet_no,pallet_count,mutation_id,ref_txn_id,quantity');
      grid.setInitWidths('60,0,60,60,*,180,120,40,130,80,120,120,80');
      grid.attachHeader([FILTERS.SELECT, '&nbsp;', FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.NUMERIC, FILTERS.TEXT, FILTERS.TEXT, FILTERS.NUMERIC]);
      grid.setColAlign('left,left,left,left,left,left,left,left,left,right,left,left,right');
      grid.setColSorting('str,str,str,str,str,str,str,str,str,int,str,str,int');
      grid.attachFooter(['&nbsp;', '&nbsp;', 'Total', gridUtils.spans.COLUMN, gridUtils.spans.COLUMN, gridUtils.spans.COLUMN, '&nbsp;', '&nbsp;', '&nbsp;', gridUtils.reducers.STATISTICS_TOTAL, '&nbsp;', '&nbsp;', gridUtils.reducers.STATISTICS_TOTAL],
        ['', '', STYLES.TEXT_BOLD, '', '', '', '', '', '', STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN, '', '', STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN]);
    } else {
      grid.setHeader(
        'SUBP.,KD. MOTIF,QLTY.,DIM.,MOTIF,TGL.,MUT.,NO. TXN.,NO. PALET,SIZE,SHADE,JUM. TXN.,QTY.',
        null,
        ['', '', '', '', '', '', '', '', '', '', '', STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_RIGHT_ALIGN]
      );
      grid.setColTypes('ro,ro,ro,ro,ro,ro_ts,ro,ro,ro,ro,ro,ron,ron');
      grid.setColumnIds('subplant,motif_id,quality,motif_dimension,motif_name,mutation_time,mutation_type,mutation_id,pallet_no,size,shading,txn_count,quantity');
      grid.setInitWidths('60,0,60,60,*,120,40,120,120,60,60,60,80');
      grid.attachHeader([FILTERS.SELECT, '&nbsp;', FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.NUMERIC, FILTERS.NUMERIC]);
      grid.setColAlign('left,left,left,left,left,left,left,left,left,left,left,right,right');
      grid.setColSorting('str,str,str,str,str,str,str,str,str,str,str,int,int');
      grid.attachFooter(['&nbsp;', '&nbsp;', 'Total', gridUtils.spans.COLUMN, gridUtils.spans.COLUMN, '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', '&nbsp;', gridUtils.reducers.STATISTICS_COUNT, gridUtils.reducers.STATISTICS_TOTAL, gridUtils.reducers.STATISTICS_TOTAL],
        ['', '', STYLES.TEXT_BOLD, '', '', '', '', '', '', '', STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_BOLD + STYLES.TEXT_RIGHT_ALIGN]);
    }
    grid.setColumnHidden(grid.getColIndexById('motif_id'), true);
    grid.setNumberFormat('0,000', grid.getColIndexById('quantity'), ',', '.');

    grid.enableSmartRendering(true, 100);
    grid.init();
    if (mutationType === COLUMN_MAP.broken_quantity) {
      grid.setColWidth(grid.getColIndexById('mutation_type'), '120');
      grid.setColumnLabel(grid.getColIndexById('mutation_type'), 'KETERANGAN');
    }

    win.progressOn();
    fetchDetailsData(mutationTypeFetch, subplants, dateFrom, dateTo, motifIds)
      .then(summaryDetails => {
        existingAggregateDetailsData.splice(0, existingAggregateDetailsData.length); // clear 'cached' data
        existingAggregateDetailsData.push(...summaryDetails);
        return processAggregateDetails(selectedMode, {
          data: summaryDetails,
          subplants,
          mutationType
        });
      })
      .then(processedDetails => showAggregateDetails(win, selectedMode, processedDetails))
      .catch(error => {
        win.progressOff();
        handleApiError(error);
      });
  }

  /**
   * Process the details based on fetched summary data.
   * @param {string} mode either of mode-summary or mode-details
   * @param details content of the details
   * @param {Array} details.data data returned from the service
   * @param {Array<string>} details.subplants selected subplant
   * @param {string} details.mutationType selected mutation type
   * @return {{data: Array, subplants: Array<string>, mutationType: string}}
   */
  function processAggregateDetails(mode, details) {
    if (mode === 'mode-details' && ![COLUMN_MAP.sales_confirmed_quantity, COLUMN_MAP.sales_in_progress_quantity].includes(details.mutationType)) {
      return details;
    }

    // summarize
    // 1. for non starting/ending stock, reduce time to date (or just use the date)
    // 2. create classifier, based on the following criteria:
    //    - final_quantity: subplant, motif_id, location
    //    - initial_quantity: subplant, motif_id
    //    - sales_confirmed_quantity/sales_in_progress_quantity: (mutation_type/mutation_id), mutation_date, subplant, motif_id
    //    - others: mutation_type, mutation_date, subplant, motif_id
    // 3. collect all mutation records to each respective classifier, and do summation on the quantity.
    // 3. done!
    let result;
    let reducer;
    switch (details.mutationType) {
      case COLUMN_MAP.sales_confirmed_quantity:
        if (mode === 'mode-summary') {
          reducer = (map, current) => {
            const id = `${current.mutation_type}_${current.mutation_date}_${current.subplant}_${current.motif_id}`;
            if (map.has(id)) {
              const val = map.get(id);
              val.quantity += current.quantity;
              val.pallet_count++;
            } else {
              map.set(id, {
                mutation_type: current.mutation_type,
                mutation_date: current.mutation_date,
                subplant: current.subplant,
                motif_id: current.motif_id,
                motif_dimension: current.motif_dimension,
                motif_name: current.motif_name,
                quality: current.quality,
                pallet_count: 1,
                quantity: current.quantity
              })
            }
            return map;
          };
        } else if (mode === 'mode-details') {
          reducer = (map, current) => {
            const id = `${current.mutation_id}_${current.mutation_date}_${current.subplant}_${current.motif_id}`;
            if (map.has(id)) {
              const val = map.get(id);
              val.quantity += current.quantity;
              val.pallet_count++;
            } else {
              map.set(id, {
                mutation_type: current.mutation_type,
                mutation_date: current.mutation_date,
                mutation_id: current.mutation_id,
                ref_txn_id: current.ref_txn_id,
                subplant: current.subplant,
                motif_id: current.motif_id,
                motif_dimension: current.motif_dimension,
                motif_name: current.motif_name,
                quality: current.quality,
                pallet_count: 1,
                quantity: current.quantity
              })
            }
            return map;
          };
        } else if (mode === 'mode-details-pallet') {
          return details;
        } else if (mode === 'mode-period') {
          reducer = (map, current) => {
            const id = `${current.mutation_type}_${current.subplant}_${current.motif_id}`;
            if (map.has(id)) {
              const val = map.get(id);
              val.quantity += current.quantity;
              val.pallet_count++;
            } else {
              map.set(id, {
                mutation_type: current.mutation_type,
                ref_txn_id: current.ref_txn_id,
                subplant: current.subplant,
                motif_id: current.motif_id,
                motif_dimension: current.motif_dimension,
                motif_name: current.motif_name,
                quality: current.quality,
                pallet_count: 1,
                quantity: current.quantity
              })
            }
            return map;
          };
        }
        break;
      case COLUMN_MAP.sales_in_progress_quantity:
        if (mode === 'mode-summary') {
          reducer = (map, current) => {
            const id = `${current.mutation_date}_${current.mutation_id}_${current.subplant}_${current.motif_id}`;
            if (map.has(id)) {
              const val = map.get(id);
              val.quantity += current.quantity;
              val.pallet_count++;
            } else {
              map.set(id, {
                mutation_type: current.mutation_type,
                mutation_date: current.mutation_date,
                subplant: current.subplant,
                motif_id: current.motif_id,
                motif_dimension: current.motif_dimension,
                motif_name: current.motif_name,
                quality: current.quality,
                pallet_count: 1,
                quantity: current.quantity
              })
            }
            return map;
          };
        } else if (mode === 'mode-details') {
          reducer = (map, current) => {
            const id = `${current.mutation_id}_${current.mutation_date}_${current.subplant}_${current.motif_id}`;
            if (map.has(id)) {
              const val = map.get(id);
              val.quantity += current.quantity;
              val.pallet_count++;
            } else {
              map.set(id, {
                mutation_type: current.mutation_type,
                mutation_date: current.mutation_date,
                mutation_id: current.mutation_id,
                subplant: current.subplant,
                motif_id: current.motif_id,
                motif_dimension: current.motif_dimension,
                motif_name: current.motif_name,
                quality: current.quality,
                pallet_count: 1,
                quantity: current.quantity
              })
            }
            return map;
          };
        } else if (mode === 'mode-details-pallet') {
          return details;
        } else if (mode === 'mode-period') {
          reducer = (map, current) => {
            const id = `${current.mutation_type}_${current.subplant}_${current.motif_id}`;
            if (map.has(id)) {
              const val = map.get(id);
              val.quantity += current.quantity;
              val.pallet_count++;
            } else {
              map.set(id, {
                mutation_type: current.mutation_type,
                subplant: current.subplant,
                motif_id: current.motif_id,
                motif_dimension: current.motif_dimension,
                motif_name: current.motif_name,
                quality: current.quality,
                pallet_count: 1,
                quantity: current.quantity
              })
            }
            return map;
          };
        }
        break;
      case COLUMN_MAP.initial_quantity:
        reducer = (map, current) => {
          const id = `${current.subplant}_${current.motif_id}`;
          if (map.has(id)) {
            const val = map.get(id);
            val.quantity += current.quantity;
            val.pallet_count++;
          } else {
            map.set(id, {
              subplant: current.subplant,
              motif_id: current.motif_id,
              motif_dimension: current.motif_dimension,
              motif_name: current.motif_name,
              quality: current.quality,
              pallet_count: 1,
              quantity: current.quantity
            })
          }
          return map;
        };
        break;
      case COLUMN_MAP.final_quantity:
        reducer = (map, current) => {
          const id = `${current.subplant}_${current.motif_id}_${current.location_id}`;
          if (map.has(id)) {
            const val = map.get(id);
            val.quantity += current.quantity;
            val.pallet_count++;
          } else {
            map.set(id, {
              subplant: current.subplant,
              motif_id: current.motif_id,
              motif_dimension: current.motif_dimension,
              motif_name: current.motif_name,
              quality: current.quality,
              location_id: current.location_id,
              pallet_count: 1,
              quantity: current.quantity
            })
          }
          return map;
        };
        break;
      default:
        if (mode === 'mode-period') {
          reducer = (map, current) => {
            const id = `${current.subplant}_${current.motif_id}_${current.mutation_type}`;
            if (map.has(id)) {
              const val = map.get(id);
              val.quantity += current.quantity;
              val.txn_count++;
            } else {
              map.set(id, {
                mutation_type: current.mutation_type,
                subplant: current.subplant,
                motif_id: current.motif_id,
                motif_dimension: current.motif_dimension,
                motif_name: current.motif_name,
                txn_count: 1,
                quality: current.quality,
                quantity: current.quantity
              })
            }
            return map;
          }
        } else if (mode === 'mode-summary') {
          reducer = (map, current) => {
            // transform mutation_time to mutation_date
            const mutationDate = moment(current.mutation_time).startOf('day').format('YYYY-MM-DD');
            const id = `${current.subplant}_${current.motif_id}_${mutationDate}_${current.mutation_type}`;
            if (map.has(id)) {
              const val = map.get(id);
              val.quantity += current.quantity;
              val.txn_count++;
            } else {
              map.set(id, {
                mutation_time: mutationDate,
                mutation_type: current.mutation_type,
                subplant: current.subplant,
                motif_id: current.motif_id,
                motif_dimension: current.motif_dimension,
                motif_name: current.motif_name,
                txn_count: 1,
                quality: current.quality,
                quantity: current.quantity
              })
            }
            return map;
          }
        }
    }
    result = Array.from(details.data.reduce(reducer, new Map()).values());

    return {
      data: result,
      subplants: details.subplants,
      mutationType: details.mutationType
    }
  }

  /**
   * Show the data from aggregate details to the window.
   * @param {dhtmlXWindowsCell} win window object
   * @param {string} mode either of mode-summary or mode-details
   * @param details content of the details
   * @param {Array} details.data processed array data.
   * @param {Array<string>} details.subplants selected subplant
   * @param {string} details.mutationType selected mutation type
   */
  function showAggregateDetails(win, mode, details) {
    const grid = win.getAttachedObject();
    const toolbar = win.getAttachedToolbar();

    win.progressOff();
    gridUtils.clearAllGridFilters(grid);
    grid.clearAll();

    if (details.data.length === 0) {
      dhtmlx.message(`Tidak ada detail mutasi ${details.mutationType} pada periode terpilih!`);
      return;
    }
    const gridData = {
      data: details.data.map(detail => {
        const mutationType = details.mutationType;
        let id;
        if (mode === 'mode-summary') {
          if (mutationType === COLUMN_MAP.final_quantity) {
            id = `${detail.subplant}_${detail.motif_id}_${detail.location_id}`;
          } else if (mutationType === COLUMN_MAP.initial_quantity) {
            id = `${detail.subplant}_${detail.motif_id}`;
          } else if (mutationType === COLUMN_MAP.sales_in_progress_quantity || mutationType === COLUMN_MAP.sales_confirmed_quantity) {
            id = `${detail.mutation_date}_${detail.mutation_type}_${detail.subplant}_${detail.motif_id}`;
          } else {
            id = `${detail.mutation_time}_${detail.mutation_type}_${detail.subplant}_${detail.motif_id}`;
          }
        } else if (mode === 'mode-details') { // details
          if (mutationType === COLUMN_MAP.initial_quantity || mutationType === COLUMN_MAP.final_quantity) {
            id = detail.pallet_no;
          } else if (mutationType === COLUMN_MAP.sales_confirmed_quantity || mutationType === COLUMN_MAP.sales_in_progress_quantity) {
            id = `${detail.subplant}_${detail.motif_id}_${detail.mutation_id}`;
          } else {
            id = `${detail.subplant}_${detail.motif_id}_${detail.mutation_id}_${detail.pallet_no}_${detail.size}_${detail.shading}`;
          }
        } else if (mode === 'mode-details-pallet') {
          if ([COLUMN_MAP.sales_confirmed_quantity, COLUMN_MAP.sales_in_progress_quantity].includes(mutationType)) {
            id = `${detail.mutation_type}_${detail.mutation_id}_${detail.mutation_date}_${detail.pallet_no}_${detail.size}_${detail.shading}`
          }
        } else if (mode === 'mode-period') {
          id = `${detail.mutation_type}_${detail.subplant}_${detail.motif_id}`;
        }
        return Object.assign(detail, { id: id });
      })
    };

    // prepare the grid (change/hide columns)
    applyAggregateGridSettings(grid, details.mutationType, mode);

    grid.parse(gridData, 'js');
    toolbar.setItemText('timestamp', moment().format());
  }

  /**
   * Changes the grid view based on mutationType and mode.
   * @param {dhtmlXGridObject} grid grid object
   * @param {string} mutationType type of mutation being shown.
   * @param {string} mode view mode, either mode-summary or mode-details
   */
  function applyAggregateGridSettings(grid, mutationType, mode) {
    switch (mutationType) {
      case COLUMN_MAP.sales_in_progress_quantity:
        if (mode === 'mode-period') {
          grid.setColumnHidden(grid.getColIndexById('mutation_id'), true);
          grid.setColumnHidden(grid.getColIndexById('pallet_no'), true);
          grid.setColumnHidden(grid.getColIndexById('mutation_date'), true);
          grid.setColumnHidden(grid.getColIndexById('pallet_count'), false);
        } else if (mode === 'mode-summary') {
          grid.setColumnHidden(grid.getColIndexById('mutation_id'), true);
          grid.setColumnHidden(grid.getColIndexById('pallet_no'), true);
          grid.setColumnHidden(grid.getColIndexById('mutation_date'), false);
          grid.setColumnHidden(grid.getColIndexById('pallet_count'), false);
        } else if (mode === 'mode-details') {
          grid.setColumnHidden(grid.getColIndexById('mutation_id'), false);
          grid.setColumnHidden(grid.getColIndexById('pallet_no'), true);
          grid.setColumnHidden(grid.getColIndexById('mutation_date'), false);
          grid.setColumnHidden(grid.getColIndexById('pallet_count'), false);
        } else { // mode-details-pallet
          grid.setColumnHidden(grid.getColIndexById('mutation_id'), false);
          grid.setColumnHidden(grid.getColIndexById('pallet_no'), false);
          grid.setColumnHidden(grid.getColIndexById('mutation_date'), false);
          grid.setColumnHidden(grid.getColIndexById('pallet_count'), true);
        }
        break;
      case COLUMN_MAP.sales_confirmed_quantity:
        if (mode === 'mode-period') {
          grid.setColumnHidden(grid.getColIndexById('ref_txn_id'), true);
          grid.setColumnHidden(grid.getColIndexById('mutation_id'), true);
          grid.setColumnHidden(grid.getColIndexById('pallet_no'), true);
          grid.setColumnHidden(grid.getColIndexById('lokasi'), true);
          grid.setColumnHidden(grid.getColIndexById('mutation_date'), true);
          grid.setColumnHidden(grid.getColIndexById('pallet_count'), false);
        } else if (mode === 'mode-summary') {
          grid.setColumnHidden(grid.getColIndexById('ref_txn_id'), true);
          grid.setColumnHidden(grid.getColIndexById('mutation_id'), true);
          grid.setColumnHidden(grid.getColIndexById('mutation_date'), false);
          grid.setColumnHidden(grid.getColIndexById('pallet_no'), true);
          grid.setColumnHidden(grid.getColIndexById('lokasi'), true);
          grid.setColumnHidden(grid.getColIndexById('pallet_count'), false);
        } else if (mode === 'mode-details') {
          grid.setColumnHidden(grid.getColIndexById('ref_txn_id'), false);
          grid.setColumnHidden(grid.getColIndexById('mutation_id'), false);
          grid.setColumnHidden(grid.getColIndexById('mutation_date'), false);
          grid.setColumnHidden(grid.getColIndexById('pallet_no'), true);
          grid.setColumnHidden(grid.getColIndexById('lokasi'), true);
          grid.setColumnHidden(grid.getColIndexById('pallet_count'), false);
        } else { // mode-details-pallet
          grid.setColumnHidden(grid.getColIndexById('ref_txn_id'), false);
          grid.setColumnHidden(grid.getColIndexById('mutation_id'), false);
          grid.setColumnHidden(grid.getColIndexById('mutation_date'), false);
          grid.setColumnHidden(grid.getColIndexById('pallet_no'), false);
          grid.setColumnHidden(grid.getColIndexById('lokasi'), false);
          grid.setColumnHidden(grid.getColIndexById('pallet_count'), true);
        }
        break;
      case COLUMN_MAP.initial_quantity:
      case COLUMN_MAP.final_quantity:
        if (mode === 'mode-summary') {
          grid.setColumnHidden(grid.getColIndexById('pallet_no'), true);
          grid.setColumnHidden(grid.getColIndexById('size'), true);
          grid.setColumnHidden(grid.getColIndexById('shading'), true);
          grid.setColumnHidden(grid.getColIndexById('pallet_count'), false);
        } else {
          grid.setColumnHidden(grid.getColIndexById('pallet_no'), false);
          grid.setColumnHidden(grid.getColIndexById('size'), false);
          grid.setColumnHidden(grid.getColIndexById('shading'), false);
          grid.setColumnHidden(grid.getColIndexById('pallet_count'), true);
        }
        break;
      default:
        if (mode === 'mode-summary') {
          grid.setColumnHidden(grid.getColIndexById('mutation_time'), false);
          grid.setColumnHidden(grid.getColIndexById('mutation_id'), true);
          grid.setColumnHidden(grid.getColIndexById('pallet_no'), true);
          grid.setColumnHidden(grid.getColIndexById('size'), true);
          grid.setColumnHidden(grid.getColIndexById('shading'), true);
          grid.setColumnHidden(grid.getColIndexById('txn_count'), false);

          grid.setColTypes('ro,ro,ro,ro,ro,ro_date,ro,ro,ro,ro,ro,ron,ron');
        } else if (mode === 'mode-period') {
          grid.setColumnHidden(grid.getColIndexById('mutation_time'), true);
          grid.setColumnHidden(grid.getColIndexById('mutation_id'), true);
          grid.setColumnHidden(grid.getColIndexById('pallet_no'), true);
          grid.setColumnHidden(grid.getColIndexById('size'), true);
          grid.setColumnHidden(grid.getColIndexById('shading'), true);
          grid.setColumnHidden(grid.getColIndexById('txn_count'), false);
        } else { // mode-details
          grid.setColumnHidden(grid.getColIndexById('mutation_time'), false);
          grid.setColumnHidden(grid.getColIndexById('mutation_id'), false);
          grid.setColumnHidden(grid.getColIndexById('pallet_no'), false);
          grid.setColumnHidden(grid.getColIndexById('size'), false);
          grid.setColumnHidden(grid.getColIndexById('shading'), false);
          grid.setColumnHidden(grid.getColIndexById('txn_count'), true);

          grid.setColTypes('ro,ro,ro,ro,ro,ro_ts,ro,ro,ro,ro,ro,ron,ron');
        }
        break;
    }
  }

  return { openMutationDetailsWindow, openAggregateMutationDetailsWindow, fetchDetailsData, COLUMN_MAP }
}));
