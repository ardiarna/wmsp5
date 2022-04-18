<?php
require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

use Security\RoleAcl;

SessionUtils::sessionStart();

$errorMessage = null;
if (!SessionUtils::isAuthenticated()) {
  // print error
  $errorMessage = 'You are not authenticated!';
}
$user = SessionUtils::getUser();

// check authorization.
$authorized = !empty($user->gua_subplants);
if ($authorized) {
  // check role
  $allowedRoles = RoleAcl::mutationReport();
  $authorized = UserRole::hasAnyRole($allowedRoles);
}

if (!$authorized) {
  $errorMessage = 'Anda tidak punya akses ke data mutasi!';
}

if (isset($errorMessage)) {
  die ($errorMessage);
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
  <title>Mutation Summary by Motif</title>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
  <meta http-equiv="X-UA-Compatible" content="IE=edge"/>

  <link rel="stylesheet" type="text/css" href="../../assets/libs/dhtmlx/dhtmlx.css"/>
  <link rel="stylesheet" type="text/css" href="../../assets/fonts/font_awesome/css/font-awesome.min.css"/>
  <link rel="stylesheet" type="text/css" href="../../assets/fonts/font_roboto/roboto.css"/>
  <style>
    html, body {
      width: 100%;
      height: 100%;
      margin: 0px;
      padding: 0px;
      overflow: hidden;
    }
  </style>

  <script src="../../assets/libs/dhtmlx/dhtmlx.js"></script>
  <script src="../../assets/libs/moment/moment-with-locales.min.js"></script>
  <script src="../../assets/libs/axios/axios.min.js"></script>
  <script src="../../assets/libs/pdfmake/pdfmake.min.js"></script>
  <script src="../../assets/libs/pdfmake/vfs_fonts.js"></script>

  <script src="../../assets/js/date-utils.js"></script>
  <script src="../../assets/js/grid-custom-types-20190704-01.js"></script>
  <script src="../../assets/js/grid-utils-20190704-01.js"></script>
  <script src="../../assets/js/WMSApi-20190711-01.js"></script>
  <script src="../../assets/js/error-handler.js"></script>
  <script src="../../assets/js/ParseError.js"></script>
  <script src="../../assets/js/views/mutation-details-20210315.js"></script>
  <script>
    const dashboard = function (moment, WMSApi, gridUtils, pdfMake, DateUtils, MutationDetails, handleApiError) {
      "use strict";
      WMSApi.setBaseUrl('../../api');

      const SUBPLANTS_PRODUCTION = [<?= '\'' . implode('\',\'', $user->gua_subplant_handover) . '\'' ?>];

      const ROOT_LAYOUT_FILTER = 'a';
      const ROOT_LAYOUT_GRID = 'b';

      moment.locale('id');
      moment.defaultFormat = 'D MMM YYYY, HH:mm:ss';

      const TEXT_CENTER_ALIGN = gridUtils.styles.TEXT_CENTER_ALIGN;
      const TEXT_RIGHT_ALIGN = gridUtils.styles.TEXT_RIGHT_ALIGN;
      const TEXT_BOLD = gridUtils.styles.TEXT_BOLD;

      const HEADER_TEXT_FILTER = gridUtils.headerFilters.TEXT;
      const HEADER_NUMERIC_FILTER = gridUtils.headerFilters.NUMERIC;
      const HEADER_SELECT_FILTER = gridUtils.headerFilters.SELECT;

      let gridToolbarItemCount, selectedSubplant = SUBPLANTS_PRODUCTION[0];
      let gridReport, gridCell;

      let filterCell, filterForm;

      // report metadata
      let firstMutationReportDay = null;
      let lastMutationReportDay = null;
      let dataTimestamp = null;
      function setupFilter(cell, subplants) {
        const subplantOptions = [];
        subplants.forEach(subplant => {
          subplantOptions.push({ text: subplant, value: subplant, selected: subplantOptions.length === 0 })
        });

        const formConfig = [
          { type: "settings", position: "label-left", labelWidth: 70, inputWidth: 160 },
          {
            type: 'calendar',
            offsetLeft: 20,
            name: 'from_date',
            label: 'Dari',
            enableTodayButton: true,
            required: true,
            dateFormat: "%Y-%m-%d",
            calendarPosition: "right",
            inputWidth: 100
          },
          { type: 'newcolumn' },
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
            inputWidth: 100
          },
          { type: 'newcolumn' },
          {
            type: 'combo',
            name: 'subplant',
            label: 'Subplant',
            options: subplantOptions,
            required: true,
            readonly: true,
            disabled: subplantOptions.length === 1,
            offsetLeft: 20,
            inputWidth: 80
          },
          { type: 'newcolumn' },
          { type: 'button', offsetLeft: 20, name: 'get-data', value: 'Dapatkan Data' }
        ];
        filterForm = cell.attachForm(formConfig);
        if (subplantOptions.length > 1) {
          filterForm.getCombo('subplant').addOption('all', 'Semua')
        }

        filterForm.attachEvent('onChange', (name, value) => {
          switch (name) {
            case 'from_date':
              filterForm.getCalendar('to_date').setSensitiveRange(value, lastMutationReportDay || null);
              return true;
            case 'to_date':
              filterForm.getCalendar('from_date').setSensitiveRange(firstMutationReportDay || null, value);
              return true;
            case 'subplant':
              selectedSubplant = value;
              return true;
          }
        });
        filterForm.attachEvent('onButtonClick', function (id) {
          if (id === 'get-data') {
            const fromDate = this.getItemValue('from_date');
            const toDate = this.getItemValue('to_date');

            fetchMutationSummary(selectedSubplant, fromDate, toDate)
          }
        }.bind(filterForm));

        fetchMutationSummaryMetadata()
          .then(() => {
            const fromDateCalendar = filterForm.getCalendar('from_date');
            const toDateCalendar = filterForm.getCalendar('to_date');

            filterForm.setItemValue('to_date', lastMutationReportDay);
            fromDateCalendar.setSensitiveRange(firstMutationReportDay, toDateCalendar.getDate());

            // get the first day of month
            filterForm.setItemValue('from_date', moment(lastMutationReportDay).startOf('month').toDate());
            toDateCalendar.setSensitiveRange(fromDateCalendar.getDate(), lastMutationReportDay);
          });
        return cell;
      }

      function fetchMutationSummaryMetadata() {
        filterCell.progressOn();
        return WMSApi.stock.fetchStockMutationSummaryMetadata()
          .then(metadata => {
            firstMutationReportDay = new Date(metadata.min_date);
            lastMutationReportDay = new Date(metadata.max_date);
            dataTimestamp = moment(metadata.generated_at).toDate();
            filterCell.progressOff();
          })
          .catch(error => {
            filterCell.progressOff();
            handleApiError(error);
          })
      }

      function setupGrid(cell) {
        const toolbarItems = [
          {
            type: 'button',
            id: 'clear-filters',
            text: 'Bersihkan Penyaring Data',
            img: 'fa fa-close',
            imgdis: 'fa fa-close',
            enabled: false
          },
          {type: 'separator'},
          {
            type: 'buttonSelect',
            id: 'summary-csv',
            text: 'Ringkasan tanpa Size Shading (CSV)',
            img: 'fa fa-file-excel-o',
            imgdis: 'fa fa-file-excel-o',
            renderSelect: false,
            enabled: false,
            options: [
              {
                type: 'button',
                id: 'summary-detail-csv',
                text: 'Ringkasan dengan Size Shading (CSV)',
                img: 'fa fa-file-excel-o',
                imgdis: 'fa fa-file-excel-o',
                enabled: false
              }
            ]
          },
          {type: 'spacer'},
          {type: 'text', id: 'timestamp', text: dataTimestamp || ''}
        ];
        gridToolbarItemCount = toolbarItems.length;
        const toolbar = cell.attachToolbar({
          iconset: 'awesome',
          items: toolbarItems
        });

        // setup grid skeleton first
        const grid = cell.attachGrid();
        gridReport = grid;

        // prepare slot for all mutation types.
        const COLSPAN =  gridUtils.spans.COLUMN + ',';
        const QTY_COL_STYLE = TEXT_RIGHT_ALIGN + TEXT_BOLD;
        grid.setHeader(`SUBP.,MOTIF ID,QLTY.,DIM.,MOTIF,QTY. AWAL,` +
          // in
          `QTY. MASUK,` + COLSPAN + COLSPAN + COLSPAN + COLSPAN + COLSPAN +
          // out
          `QTY. KELUAR,` + COLSPAN + COLSPAN + COLSPAN + COLSPAN + COLSPAN + COLSPAN + COLSPAN + COLSPAN + COLSPAN +
          'QTY. AKHIR',
          null,
          [
            '', '', '', '', '', QTY_COL_STYLE,
            // in
            TEXT_CENTER_ALIGN + TEXT_BOLD, '', '', '', '', QTY_COL_STYLE,
            // out
            TEXT_CENTER_ALIGN + TEXT_BOLD, '', '', '', '', '', '', '', '', QTY_COL_STYLE,
            // final
            QTY_COL_STYLE
          ]
        );
        grid.attachHeader([
          // subplant, motif_id, quality, motif_dimension, motif_name, initial_quantity
          gridUtils.spans.ROW, gridUtils.spans.ROW, gridUtils.spans.ROW, gridUtils.spans.ROW, gridUtils.spans.ROW, gridUtils.spans.ROW,
          // in
          'PROD.', 'PLM', 'MUT.', 'ADJ.', 'DWG.', 'TTL.',
          // out
          'MUT.', 'ADJ.', 'RET. PROD.', 'PECAH', 'SALES (IN PROG.)', 'SALES (CONFIRM)', 'FOC', 'SMP.', 'DWG.', 'TTL.',
          // final_quantity
          gridUtils.spans.ROW,
        ], [
          '', '', '', '', '', '',
          TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, QTY_COL_STYLE,
          TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, QTY_COL_STYLE,
          ''
        ]);
        grid.attachHeader([
          //  subplant, motif_id, quality, motif_dimension, motif_name, initial_quantity
          HEADER_SELECT_FILTER, HEADER_TEXT_FILTER, HEADER_SELECT_FILTER, HEADER_SELECT_FILTER, HEADER_TEXT_FILTER, HEADER_NUMERIC_FILTER,
          // in
          HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER,
          // out
          HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER,
          // final_quantity
          HEADER_NUMERIC_FILTER
        ]);

        // setting ids and styling
        grid.setColumnIds(
          'subplant,motif_id,quality,motif_dimension,motif_name,initial_quantity,' +
          // in
          'prod_initial_quantity,manual_initial_quantity,in_mut_quantity,in_adjusted_quantity,in_downgrade_quantity,in_quantity_total,' +
          // out
          'out_mut_quantity,out_adjusted_quantity,returned_quantity,broken_quantity,sales_in_progress_quantity,sales_confirmed_quantity,foc_quantity,sample_quantity,out_downgrade_quantity,out_quantity_total,' +
           // final
          'final_quantity'
        );
        grid.setColSorting(
          //  motif_id, motif_dimension, motif_name, initial_quantity
          'str,str,str,str,str,int,' +
          // in
          'int,int,int,int,int,int,' +
          // out
          'int,int,int,int,int,int,int,int,int,int,' +
          // final
          'int'
        );
        grid.setColTypes(
          // subplant, motif_id, quality, motif_dimension, motif_name, initial_quantity
          'ro,ro,ro,ro,ro,ron,' +
          // in
          'ron,ron,ron,ron,ron,ron,' +
          // out
          'ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,' +
          // final_quantity
          'ron'
        );
        grid.setInitWidths(
          //  subplant, motif_id, quality, motif_dimension, motif_name, initial_quantity
          '60,80,80,80,200,80,' +
          // in
          '80,80,80,80,80,80,' +
          // out
          '80,80,80,80,80,80,80,80,80,80,' +
          // final_quantity
          '80'
        );
        grid.setColAlign('left,left,left,left,left,right,' +
          // in
          'right,right,right,right,right,right,' +
          // out
          'right,right,right,right,right,right,right,right,right,right,' +
          // total
          'right'
        );

        const REDUCERS_TOTAL = gridUtils.reducers.STATISTICS_TOTAL;
        grid.attachFooter([
          '', '', '', 'TOTAL', gridUtils.spans.COLUMN,
          // initial_quantity
          REDUCERS_TOTAL,
          // in
          REDUCERS_TOTAL, REDUCERS_TOTAL, REDUCERS_TOTAL, REDUCERS_TOTAL, REDUCERS_TOTAL, REDUCERS_TOTAL,
          // out
          REDUCERS_TOTAL, REDUCERS_TOTAL, REDUCERS_TOTAL, REDUCERS_TOTAL, REDUCERS_TOTAL, REDUCERS_TOTAL, REDUCERS_TOTAL, REDUCERS_TOTAL, REDUCERS_TOTAL, REDUCERS_TOTAL,
          // total
          REDUCERS_TOTAL,
        ], [
          '', '', '', QTY_COL_STYLE, '',
          // initial_quantity
          QTY_COL_STYLE,
          // in
          TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, QTY_COL_STYLE,
          // out
          TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, QTY_COL_STYLE,
          // final
          QTY_COL_STYLE,
        ]);
        grid.setColumnColor('#FFF,#FFF,#FFF,#FFF,#FFF,#ADFF2F,' +
          // in
          '#FFF,#FFF,#FFF,#FFF,#FFF,#32CD32,' +
          // out
          '#FFF,#FFF,#FFF,#FFF,#FFF,#FFF,#FFF,#FFF,#FFF,#FA8072,' +
          // total
          '#87CEFA');
        grid.setColumnHidden(grid.getColIndexById('motif_id'), true);
        grid.attachEvent('onRowDblClicked', (rowIdx, colIdx) => {
          const selectedColId = grid.getColumnId(colIdx);
          const queryMap = MutationDetails.COLUMN_MAP[selectedColId] || null;
          if (queryMap) {
            const subplant = grid.cells(rowIdx, grid.getColIndexById('subplant')).getValue();
            const motifId = grid.cells(rowIdx, grid.getColIndexById('motif_id')).getValue();
            const motifName = grid.cells(rowIdx, grid.getColIndexById('motif_name')).getValue();
            const fromDate = filterForm.getItemValue('from_date');
            const toDate = filterForm.getItemValue('to_date');

            let colName = selectedColId === 'initial_quantity' || selectedColId === 'final_quantity' ?
              grid.getColumnLabel(colIdx, 0) :
              grid.getColumnLabel(colIdx, 1);
            colName += selectedColId === 'out_quantity_total' ? ' OUT' :
              selectedColId === 'in_quantity_total' ? ' IN' : '';

            MutationDetails.openMutationDetailsWindow(windows, queryMap, subplant, fromDate, toDate, motifId, motifName, colName);
          }
        });
        grid.init();
        dhtmlxEvent(grid.ftr, 'dblclick', ev => {
          // get current selected index
          const tblCellNode = ev.target.parentNode;
          const tblRowNode = tblCellNode.parentNode;
          let colIdx = Array.from(tblRowNode.children).indexOf(tblCellNode);
          // offset compensation
          colIdx = colIdx === 0 ? colIdx : colIdx + 1;
          const selectedColId = grid.getColumnId(colIdx);
          const queryMap = MutationDetails.COLUMN_MAP[selectedColId] || null;
          if (queryMap) {
            const subplants = new Set();
            const motifIds = new Set();

            const subplantIdx = grid.getColIndexById('subplant');
            const motifIdIdx = grid.getColIndexById('motif_id');
            grid.forEachRowA(rowId => {
              const subplant = grid.cells(rowId, subplantIdx).getValue();
              subplants.add(subplant);

              const motifId = grid.cells(rowId, motifIdIdx).getValue();
              motifIds.add(motifId);
            });

            let colName = selectedColId === 'initial_quantity' || selectedColId === 'final_quantity' ?
              grid.getColumnLabel(colIdx, 0) :
              grid.getColumnLabel(colIdx, 1);
            colName += selectedColId === 'out_quantity_total' ? ' OUT' :
              selectedColId === 'in_quantity_total' ? ' IN' : '';
            const fromDate = filterForm.getItemValue('from_date');
            const toDate = filterForm.getItemValue('to_date');

            MutationDetails.openAggregateMutationDetailsWindow(windows, queryMap, Array.from(subplants), fromDate, toDate, Array.from(motifIds), colName)
          }
        });

        // setup stuff that can be set per column.
        const gridColCount = grid.getColumnsNum();
        for (let i = 0; i < gridColCount; i++) {
          const colId = grid.getColumnId(i);
          if (!grid.isColumnHidden(i) && colId.includes('quantity')) {
            grid.setNumberFormat('0,000', grid.getColIndexById(colId), ',', '.');
          }
        }

        // setup toolbar interaction with grid
        toolbar.attachEvent('onClick', id => {
          if (id === 'clear-filters') {
            gridUtils.clearAllGridFilters(gridReport);
          } else if (id.startsWith('summary')) {
            gridCell.progressOn();

            // get all motifs for export
            const motifIds = [];
            const subplants = [];
            const visibleRowCount = grid.getRowsNum();
            const colMotifId = grid.getColIndexById('motif_id');
            const colSubplant = grid.getColIndexById('subplant');
            for (let i = 0; i < visibleRowCount; i++) {
              const rowId = grid.getRowId(i);
              const subplant = grid.cells(rowId, colSubplant).getValue();
              if (subplants.indexOf(subplant) === -1) {
                subplants.push(subplant)
              }
              const motifId = grid.cells(rowId, colMotifId).getValue();
              motifIds.push(motifId);
            }

            const fromDate = filterForm.getItemValue('from_date');
            const toDate = filterForm.getItemValue('to_date');
            if (id.includes('detail')) {
              WMSApi.stock.fetchStockMutationSummaryByMotifSizeShading(subplants.length > 1 ? 'all' : subplants[0], fromDate, toDate, motifIds)
                .then(response => {
                  exportToCsv(selectedSubplant, fromDate, toDate, response.data, true);
                  gridCell.progressOff();
                })
                .catch(error => {
                  gridCell.progressOff();
                  handleApiError(error);
                })
            } else {
              WMSApi.stock.fetchStockMutationSummaryByMotif(subplants.length > 1 ? 'all' : subplants[0], fromDate, toDate, motifIds)
                .then(response => {
                  exportToCsv(selectedSubplant, fromDate, toDate, response.data);
                  gridCell.progressOff();
                })
                .catch(error => {
                  gridCell.progressOff();
                  handleApiError(error);
                })
            }
          }
        });

        function exportToCsv(subplant, fromDate, toDate, stockMutationSummary, withSizeShading = false) {
          const headers = [
            ['subplant', 'subplant'],
            ['quality', 'kualitas'],
            ['motif_dimension', 'ukuran_motif'],
            ['motif_id', 'kode_motif'],
            ['motif_name', 'nama_motif'],

            ['initial_quantity', 'stok_awal'],

            ['prod_initial_quantity', 'stok_masuk_produksi'],
            ['manual_initial_quantity', 'stok_masuk_plm'],
            ['in_mut_quantity', 'stok_masuk_mutasi'],
            ['in_adjusted_quantity', 'stok_masuk_adjustment'],
            ['in_downgrade_quantity', 'stok_masuk_downgrade'],
            ['in_quantity_total', 'stok_masuk_total'],

            ['out_mut_quantity', 'stok_keluar_mutasi'],
            ['out_adjusted_quantity', 'stok_keluar_adjustment'],
            ['returned_quantity', 'stok_keluar_retur_produksi'],
            ['broken_quantity', 'stok_keluar_pecah'],
            ['sales_in_progress_quantity', 'stok_keluar_jual_dalam_proses'],
            ['sales_confirmed_quantity', 'stok_keluar_jual_terkonfirm'],
            ['foc_quantity', 'stok_keluar_foc'],
            ['sample_quantity', 'stok_keluar_sample'],
            ['out_downgrade_quantity', 'stok_keluar_downgrade'],
            ['out_quantity_total', 'stok_keluar_total'],

            ['final_quantity', 'stok_akhir'],
          ];
          if (withSizeShading) {
            headers.splice(3, 0, ['size', 'size'], ['shading', 'shading']);
          }

          const csvContent = [];
          stockMutationSummary.forEach(record => {
            const row = [];
            headers.forEach(header => {
              row.push(record[header[0]]);
            });
            csvContent.push(row)
          });
          const headerExport = headers.map(header => header[1]);
          const fromDateExport = fromDate instanceof Date ? DateUtils.toSqlDate(fromDate) : fromDate.toString();
          const toDateExport = toDate instanceof Date ? DateUtils.toSqlDate(toDate) : toDate.toString();
          const subplantExport = subplant === 'all' ? 'Plant <?= PlantIdHelper::getCurrentPlant() ?>' : `Subplant ${subplant}`;
          let filename = `${fromDateExport}-${toDateExport} - Ringkasan Mutasi Palet ${subplantExport}`;
          if (withSizeShading) {
            filename += ' Detail';
          }
          gridUtils.downloadCSV(filename, csvContent, headerExport)
        }

        return cell;
      }

      function fetchMutationSummary(selectedSubplant, fromDate, toDate, motifIds = []) {
        gridCell.progressOn();

        return WMSApi.stock.fetchStockMutationSummaryByMotif(selectedSubplant, fromDate, toDate, motifIds)
          .then(response => ({
            data: response.data.map(summary => Object.assign({ id: `${summary.subplant}_${summary.motif_id}` }, summary)),
            last_updated_at: response.last_updated_at
          }))
          .then(result => {
            gridCell.progressOff();
            gridReport.clearAll();
            gridReport.setColumnHidden(gridReport.getColIndexById('subplant'), selectedSubplant !== 'all');

            const gridToolbar = gridCell.getAttachedToolbar();
            if (result.data.length === 0) {
              dhtmlx.alert({
                type: 'alert-warning',
                title: 'Tidak ada Data',
                text: `Tidak ada data mutasi dari ${DateUtils.toSqlDate(fromDate)} hingga ${DateUtils.toSqlDate(toDate)}!`
              });
              gridToolbar.forEachItem(itemId => {
                gridToolbar.disableItem(itemId);
                if (gridToolbar.getType(itemId) === 'buttonSelect') {
                  gridToolbar.forEachListOption(itemId, optionId => {
                    gridToolbar.disableListOption(itemId, optionId)
                  })
                }
              });
              return;
            }
            gridToolbar.forEachItem(itemId => {
              gridToolbar.enableItem(itemId);
              if (gridToolbar.getType(itemId) === 'buttonSelect') {
                gridToolbar.forEachListOption(itemId, optionId => {
                  gridToolbar.enableListOption(itemId, optionId)
                })
              }
            });

            // set the timestamp
            const lastUpdatedAt = moment(result.last_updated_at);
            dataTimestamp = lastUpdatedAt.toDate();
            gridCell.getAttachedToolbar().setItemText('timestamp', lastUpdatedAt.format() || '');
            delete result.last_updated_at;

            gridReport.parse(result, 'js');
            gridReport.filterByAll();
          })
          .catch(error => {
            gridCell.progressOff();
            handleApiError(error);
          })
      }


      // init everything
      let rootLayout;
      let windows;

      function doOnLoad() {
        rootLayout = new dhtmlXLayoutObject({
          parent: document.body,
          pattern: '2E',

          cells: [
            { id: ROOT_LAYOUT_FILTER, header: true, text: 'Laporan Mutasi', height: 90 },
            { id: ROOT_LAYOUT_GRID, header: false, text: '' }
          ]
        });
        windows = new dhtmlXWindows();

        filterCell = rootLayout.cells(ROOT_LAYOUT_FILTER);
        filterCell = setupFilter(filterCell, SUBPLANTS_PRODUCTION);

        gridCell = rootLayout.cells(ROOT_LAYOUT_GRID);
        gridCell = setupGrid(gridCell);
      }

      return { doOnLoad }
    }(moment, WMSApi, gridUtils, pdfMake, DateUtils, MutationDetails, handleApiError)
  </script>
</head>
<body onload="dashboard.doOnLoad();">

</body>
</html>
