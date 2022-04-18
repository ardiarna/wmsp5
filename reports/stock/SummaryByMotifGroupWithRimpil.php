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
if (isset($errorMessage)) {
  die ($errorMessage);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <title>Stock Summary for Sales</title>
  <meta charset="utf-8"/>
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
  <script src="../../assets/js/ParseError.js"></script>
  <script src="../../assets/js/timer.js"></script>
  <script src="../../assets/js/error-handler.js"></script>
  <script>
    const dashboard = function (moment, WMSApi, gridUtils, pdfMake, handleApiError, Timer) {
      "use strict";
      WMSApi.setBaseUrl('../../api');

      const USERID = '<?= $user->gua_kode ?>';
      const SUBPLANTS_PRODUCTION = [];
      const AGGREGATE_MODES = ['all', 'other', 'local'];

      const ROOT_LAYOUT_PALLETS_BY_MOTIF_ID = 'a';

      moment.locale('id');
      moment.defaultFormat = 'D MMM YYYY, HH:mm:ss';

      const TEXT_CENTER_ALIGN = gridUtils.styles.TEXT_CENTER_ALIGN;
      const TEXT_RIGHT_ALIGN = gridUtils.styles.TEXT_RIGHT_ALIGN;
      const TEXT_BOLD = gridUtils.styles.TEXT_BOLD;

      const HEADER_TEXT_FILTER = gridUtils.headerFilters.TEXT;
      const HEADER_NUMERIC_FILTER = gridUtils.headerFilters.NUMERIC;
      const HEADER_SELECT_FILTER = gridUtils.headerFilters.SELECT;

      let palletsByMotifToolbarItemCount, palletsByMotifSelectedSubplant;
      let palletsByMotifGrid, palletsByMotifCell;

      function setupPalletsByMotifCell(cell) {
        palletsByMotifCell = cell;
        const toolbarItems = [
          {type: 'text', id: 'timestamp', text: ''},
          {type: 'separator'},
          {type: 'button', id: 'refresh', text: 'Segarkan', img: 'fa fa-refresh', imgdis: 'fa fa-refresh'},
          {
            type: 'buttonSelect',
            id: 'summary-pdf',
            text: 'Ringkasan tanpa Rimpil (PDF)',
            img: 'fa fa-file-pdf-o',
            imgdis: 'fa fa-file-pdf-o',
            renderSelect: false,
            options: [
              {
                type: 'button',
                id: 'summary-csv',
                text: 'Ringkasan tanpa Rimpil (CSV)',
                img: 'fa fa-file-excel-o',
                imgdis: 'fa fa-file-excel-o'
              },
              {
                type: 'button',
                id: 'summary-rimpil-pdf',
                text: 'Ringkasan dengan Rimpil (PDF)',
                img: 'fa fa-file-pdf-o',
                imgdis: 'fa fa-file-pdf-o',
              },
              {
                type: 'button',
                id: 'summary-rimpil-csv',
                text: 'Ringkasan dengan Rimpil (CSV)',
                img: 'fa fa-file-excel-o',
                imgdis: 'fa fa-file-excel-o'
              },
            ]
          },
          {type: 'spacer'},
          {type: 'text', id: 'text-subplant', text: 'Subplant'},
          {type: 'separator'}
        ];
        palletsByMotifToolbarItemCount = toolbarItems.length;
        const toolbar = cell.attachToolbar({
          iconset: 'awesome',
          items: toolbarItems
        });

        // setup grid skeleton first
        const grid = cell.attachGrid();
        palletsByMotifGrid = grid;

        // prepare slot for 2x2 quantity columns.
        // hide as required.
        grid.setHeader(`KD. GDG.,SUBP.,MOTIF ID,DIM.,MOTIF,` +
          'QTY,' + gridUtils.spans.COLUMN + ',' + gridUtils.spans.COLUMN + ',' + gridUtils.spans.COLUMN + ',' + gridUtils.spans.COLUMN + ',' + // normal/besar
          gridUtils.spans.COLUMN + ',' + gridUtils.spans.COLUMN + ',' + gridUtils.spans.COLUMN + ',' + gridUtils.spans.COLUMN + ',' + gridUtils.spans.COLUMN + ',' + // rimpil
          'TTL. QTY.',
          null,
          [
            '', '', '', '', '',
            TEXT_CENTER_ALIGN,
            '', '', '', '', '', // normal/besar
            '', '', '', '', '', // rimpil
            TEXT_RIGHT_ALIGN
          ]
        );
        grid.attachHeader([
          // location_subplant, subplant, motif_group_id, motif_dimension, motif_group_name
          gridUtils.spans.ROW, gridUtils.spans.ROW, gridUtils.spans.ROW, gridUtils.spans.ROW, gridUtils.spans.ROW,
          'BESAR', gridUtils.spans.COLUMN, gridUtils.spans.COLUMN, gridUtils.spans.COLUMN, gridUtils.spans.COLUMN, // normal
          'RIMPIL', gridUtils.spans.COLUMN, gridUtils.spans.COLUMN, gridUtils.spans.COLUMN, gridUtils.spans.COLUMN, // rimpil
          gridUtils.spans.ROW,
        ], [
          '', '', '', '', '',
          TEXT_CENTER_ALIGN, '', '', '', '',
          TEXT_CENTER_ALIGN, '', '', '', '',
          ''
        ]);
        grid.attachHeader([
          // location_subplant, subplant, motif_group_id, motif_dimension, motif_group_name, subtotal
          gridUtils.spans.ROW, gridUtils.spans.ROW, gridUtils.spans.ROW, gridUtils.spans.ROW, gridUtils.spans.ROW,
          'EXP', 'ECO', 'BL EXP', 'BL ECO', 'SUBT.',
          'EXP', 'ECO', 'BL EXP', 'BL ECO', 'SUBT.',
          gridUtils.spans.ROW,
        ], [
          '', '', '', '', '',
          TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN,
          TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN,
          ''
        ]);
        grid.attachHeader([
          // location_subplant, subplant, motif_group_id, motif_dimension, motif_group_name
          HEADER_SELECT_FILTER, HEADER_SELECT_FILTER, HEADER_TEXT_FILTER, HEADER_SELECT_FILTER, HEADER_TEXT_FILTER,
          // base_qty_normal
          HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER,
          // base_qty_rimpil
          HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER,
          // ttl_qty
          HEADER_NUMERIC_FILTER
        ]);
        const quantityColumns = [
          'quantity_exp_normal', 'quantity_eco_normal', 'quantity_block_exp_normal', 'quantity_block_eco_normal', 'quantity_subtotal_normal',
          'quantity_exp_rimpil', 'quantity_eco_rimpil', 'quantity_block_exp_rimpil', 'quantity_block_eco_rimpil', 'quantity_subtotal_rimpil',
          'quantity_total'
        ];

        grid.setColumnIds('location_subplant,subplant,motif_group_id,motif_dimension,motif_group_name,' + quantityColumns.join(','));
        grid.setColSorting("str,str,str,str,str,int,int,int,int,int,int,int,int,int,int,int");
        grid.setColTypes("ro,ro,ro,ro,ro,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron,ron");
        grid.setInitWidths("60,60,0,80,*,70,70,70,70,70,70,70,70,70,70,70");
        grid.setColumnMinWidth("60,60,0,80,270,70,70,70,70,70,70,70,70,70,70,70");
        grid.setColumnHidden(grid.getColIndexById('motif_group_id'), true);

        quantityColumns.forEach(col => {
          grid.setNumberFormat('0,000', grid.getColIndexById(col), ',', '.');
        });
        const quantityColStyle = TEXT_RIGHT_ALIGN + gridUtils.styles.TEXT_BOLD;
        grid.attachFooter([
          '', '', '', 'TOTAL', gridUtils.spans.COLUMN,
          gridUtils.reducers.STATISTICS_TOTAL, gridUtils.reducers.STATISTICS_TOTAL, gridUtils.reducers.STATISTICS_TOTAL, gridUtils.reducers.STATISTICS_TOTAL, gridUtils.reducers.STATISTICS_TOTAL, // normal/besar
          gridUtils.reducers.STATISTICS_TOTAL, gridUtils.reducers.STATISTICS_TOTAL, gridUtils.reducers.STATISTICS_TOTAL, gridUtils.reducers.STATISTICS_TOTAL, gridUtils.reducers.STATISTICS_TOTAL, // rimpil
          gridUtils.reducers.STATISTICS_TOTAL
        ], [
          '', '', '', TEXT_RIGHT_ALIGN + gridUtils.styles.TEXT_BOLD, '',
          quantityColStyle, quantityColStyle, quantityColStyle, quantityColStyle, quantityColStyle, // normal/besar
          quantityColStyle, quantityColStyle, quantityColStyle, quantityColStyle, quantityColStyle, // rimpil
          quantityColStyle
        ]);

        grid.setColAlign("left,left,left,left,left,right,right,right,right,right,right,right,right,right,right,right");
        grid.init();

        grid.attachEvent('onRowDblClicked', openMotifGroupDetails);

        // setup toolbar interaction with grid
        toolbar.attachEvent('onClick', id => {
          if (id === 'refresh') {
            fetchMotifGroupsAvailableForSales(palletsByMotifSelectedSubplant)
              .then(() => {
                refreshPalletByMotifSummaryTimer.reset(DEFAULT_INTERVAL_LENGTH)
              })
          } else if (id.startsWith('summary')) {
            palletsByMotifCell.progressOn();

            const motifSpecs = [];
            const visibleRowCount = palletsByMotifGrid.getRowsNum();
            const motifGroupIdColIndex = palletsByMotifGrid.getColIndexById('motif_group_name');
            for (let i = 0; i < visibleRowCount; i++) {
              const rowId = palletsByMotifGrid.getRowId(i);
              motifSpecs.push(palletsByMotifGrid.cells(rowId, motifGroupIdColIndex).getValue())
            }

            const withRimpil = id.includes('rimpil');
            if (id.endsWith('pdf')) { // generate PDF report
              generateSummaryPDFWithRimpil(palletsByMotifSelectedSubplant, motifSpecs, withRimpil)
                .then(dd => {
                  palletsByMotifCell.progressOff();
                  pdfMake.createPdf(dd)
                    .getBlob(blob => {
                      const multiSubplant = palletsByMotifSelectedSubplant === 'all' || palletsByMotifSelectedSubplant === 'other';
                      let title = multiSubplant ?
                        `Ringkasan Stok Keramik Plant ${SUBPLANTS_PRODUCTION[0][0]}` :
                        `Ringkasan Stok Keramik Subplant ${palletsByMotifSelectedSubplant}`;
                      if (palletsByMotifSelectedSubplant === 'other') {
                        title += ' - Plant Lain'
                      }
                      const filename = moment().format('YYYY-MM-DD') +
                        ' - ' +
                        `${title}.pdf`;
                      gridUtils.openPDFWindow(title, filename, blob);
                    });
                })
                .catch(error => {
                  palletsByMotifCell.progressOff();
                  handleApiError(error);
                })
            } else if (id.endsWith('csv')) { // generate CSV report
              generateSummaryCSV(palletsByMotifSelectedSubplant, motifSpecs, withRimpil)
                .then(() => {
                  palletsByMotifCell.progressOff();
                })
                .catch(error => {
                  palletsByMotifCell.progressOff();
                  handleApiError(error);
                })
            }
          }
        });
        toolbar.attachEvent('onBeforeStateChange', id => id !== palletsByMotifSelectedSubplant);
        toolbar.attachEvent('onStateChange', (id, newState) => {
          if (newState === true) {
            palletsByMotifSelectedSubplant = id;
            fetchMotifGroupsAvailableForSales(id)
              .then(() => {
                refreshPalletByMotifSummaryTimer.reset(DEFAULT_INTERVAL_LENGTH)
              });
            // disable report for all subplant.
            toolbar.forEachItem(itemId => {
              const itemType = toolbar.getType(itemId);
              if (itemType === 'buttonTwoState' && itemId !== palletsByMotifSelectedSubplant) {
                toolbar.setItemState(itemId, false);
              }
            })
          }
        })
      }

      function fetchMotifGroupsAvailableForSales(selectedSubplant) {
        const layout = rootLayout.cells(ROOT_LAYOUT_PALLETS_BY_MOTIF_ID);
        layout.progressOn();

        return WMSApi.stock.fetchMotifGroups(selectedSubplant, [], true)
          .then(stockSummary => {
            const qualities = stockSummary.qualities;
            const summaries = stockSummary.data.map(summary => {
              const row = {
                id: `${summary.location_subplant}_${summary.production_subplant}_${summary.motif_group_name}`,
                location_subplant: summary.location_subplant,
                subplant: summary.production_subplant,
                motif_group_id: summary.motif_group_id,
                motif_dimension: summary.motif_dimension,
                motif_group_name: summary.motif_group_name
              };
              let quantity_total = 0;
              qualities.forEach(quality => {
                const quantity = summary.quantity[quality];
                const block_qty = summary.block_qty[quality];

                row[`quantity_${quality.toLowerCase()}_rimpil`] = quantity.rimpil - block_qty.rimpil;

                row[`quantity_block_${quality.toLowerCase()}_rimpil`] = block_qty.rimpil;

                row['quantity_subtotal_rimpil'] = row.hasOwnProperty('quantity_subtotal_rimpil') ? row['quantity_subtotal_rimpil'] + quantity.rimpil : quantity.rimpil;

                row[`quantity_${quality.toLowerCase()}_normal`] = quantity.normal - block_qty.normal;

                row[`quantity_block_${quality.toLowerCase()}_normal`] = block_qty.normal;

                row['quantity_subtotal_normal'] = row.hasOwnProperty('quantity_subtotal_normal') ? row['quantity_subtotal_normal'] + quantity.normal : quantity.normal;

                quantity_total += quantity.rimpil + quantity.normal;
              });
              row['quantity_total'] = quantity_total;
              return row
            });
            return {
              data: summaries
            }
          })
          .then(result => {
            let hideSubplantColumn = AGGREGATE_MODES.findIndex(subplant => subplant === selectedSubplant) === -1;
            if (hideSubplantColumn) {
              // count the number of subplants in the dataset
              const subplantCount = new Set(result.data.map(summary => summary.subplant)).size;
              hideSubplantColumn = subplantCount === 1;
            }
            palletsByMotifGrid.setColumnHidden(palletsByMotifGrid.getColIndexById('subplant'), hideSubplantColumn); // no need to show the subplant
            palletsByMotifGrid.clearAll();

            palletsByMotifGrid.parse({data: result.data}, 'js');
            palletsByMotifCell.getAttachedToolbar().setItemText('timestamp', moment().format());

            palletsByMotifGrid.filterByAll();
            rootLayout.cells(ROOT_LAYOUT_PALLETS_BY_MOTIF_ID).progressOff();
          })
          .catch(error => {
            rootLayout.cells(ROOT_LAYOUT_PALLETS_BY_MOTIF_ID).progressOff();
            handleApiError(error);
          })
      }

      // init everything
      let rootLayout;

      function doOnLoad() {
        rootLayout = new dhtmlXLayoutObject({
          parent: document.body,
          pattern: '1C',

          cells: [
            {id: ROOT_LAYOUT_PALLETS_BY_MOTIF_ID, header: true, text: 'Ringkasan Stok untuk Penjualan'}
          ]
        });

        const palletsByMotifCell = rootLayout.cells(ROOT_LAYOUT_PALLETS_BY_MOTIF_ID);
        setupPalletsByMotifCell(palletsByMotifCell);

        fetchUserDetails();
      }

      function fetchUserDetails() {
        return WMSApi.auth.getCurrentUserDetails()
          .then(userDetails => {
            SUBPLANTS_PRODUCTION.splice(0, SUBPLANTS_PRODUCTION.length, ...userDetails.subplants_handover);

            const palletsByMotifToolbar = rootLayout.cells(ROOT_LAYOUT_PALLETS_BY_MOTIF_ID).getAttachedToolbar();

            // add all for production
            if (SUBPLANTS_PRODUCTION.length > 1 || userDetails.subplants_other === true) {
              palletsByMotifToolbar.addButtonTwoState('all', ++palletsByMotifToolbarItemCount, 'Semua');
            }
            if (userDetails.subplants_other === true) {
              palletsByMotifToolbar.addButtonTwoState('other', ++palletsByMotifToolbarItemCount, 'Plant Lain');
              palletsByMotifToolbar.addButtonTwoState('local', ++palletsByMotifToolbarItemCount, `Plant ${SUBPLANTS_PRODUCTION[0][0]}`);
            }

            SUBPLANTS_PRODUCTION.forEach(subplant => {
              palletsByMotifToolbar.addButtonTwoState(subplant, ++palletsByMotifToolbarItemCount, subplant);
            });
            const selectedProductionSubplant = SUBPLANTS_PRODUCTION.length > 1 ? 'all' : SUBPLANTS_PRODUCTION[0];
            palletsByMotifToolbar.setItemState(selectedProductionSubplant, true);
            palletsByMotifSelectedSubplant = selectedProductionSubplant;

            return {selectedProductionSubplant};
          })
          .then(({selectedProductionSubplant}) => {
            fetchMotifGroupsAvailableForSales(selectedProductionSubplant);
          })
          .catch(error => {
            handleApiError(error);
          })
      }

      // open summary of pallet by motif group,
      // create windowCell
      function openMotifGroupDetails(rowId) {
        // noinspection JSPotentiallyInvalidConstructorUsage
        const windowCreator = new dhtmlXWindows();
        const windowCell = windowCreator.createWindow('w1', 0, 0, 750, 450);
        windowCell.centerOnScreen();
        windowCell.setModal(true);
        windowCell.button("park").hide();
        windowCell.button("minmax1").hide();

        const motifName = palletsByMotifGrid.cells(rowId, palletsByMotifGrid.getColIndexById('motif_group_name')).getValue();
        const motifSpec = motifName;
        const subplant = palletsByMotifGrid.cells(rowId, palletsByMotifGrid.getColIndexById('subplant')).getValue();
        const locationSubplant = palletsByMotifGrid.cells(rowId, palletsByMotifGrid.getColIndexById('location_subplant')).getValue();
        const title = `Motif ${motifName} - Subplant ${subplant}`;
        windowCell.setText(title);

        const windowToolbar = windowCell.attachToolbar({
          iconset: 'awesome',
          items: [
            {type: 'button', id: 'refresh', text: 'Segarkan', img: 'fa fa-refresh'},
            {type: 'separator'},
            {type: 'button', id: 'clear_filters', text: 'Bersihkan Penyaring Data', img: 'fa fa-close'},
            {type: 'separator'},
            {type: 'button', id: 'print', text: 'Cetak', img: 'fa fa-print'},
            {type: 'button', id: 'export_csv', text: 'Ke CSV', img: 'fa fa-file-excel-o'},
            {type: 'button', id: 'export_pdf', text: 'Ke PDF', img: 'fa fa-file-pdf-o'},
            {type: 'spacer'},
            {type: 'text', id: 'timestamp', text: ''}
          ]
        });
        windowToolbar.attachEvent('onClick', itemId => {
          const filename = `${moment().format('YYYY-MM-DD')} - ${title}`;
          switch (itemId) {
            case 'refresh':
              fetchWindowData(subplant, locationSubplant, motifSpec);
              break;
            case 'clear_filters':
              gridUtils.clearAllGridFilters(windowGrid);
              break;
            case 'print':
              gridUtils.generateFilteredPdf(windowGrid, title, USERID)
                .getBlob(blob => {
                  gridUtils.openPDFWindow(title, filename, blob, windowCell, windowCreator)
                }, {autoPrint: true});
              break;
            case 'export_csv':
              gridUtils.downloadFilteredCSV(windowGrid, filename);
              break;
            case 'export_pdf':
              gridUtils.generateFilteredPdf(windowGrid, title, USERID)
                .download(filename);
              break;
          }
        });

        // setup grid
        const windowGrid = windowCell.attachGrid();
        windowGrid.setHeader(['QLTY.', 'SIZE', 'SHADING', 'RIMPIL', 'TTL. PLT.', 'STOCK AVAILABLE', 'BLOCK', 'TTL. QTY.'], null,
          ['', '', '', '', TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN, TEXT_RIGHT_ALIGN]);
        windowGrid.attachHeader([HEADER_SELECT_FILTER, HEADER_SELECT_FILTER, HEADER_SELECT_FILTER, HEADER_SELECT_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER, HEADER_NUMERIC_FILTER]);
        windowGrid.setInitWidths('*,*,*,*,*,*,*,*');
        windowGrid.setColAlign('left,left,left,left,right,right,right,right');
        windowGrid.setColumnIds('quality,size,shading,is_rimpil,pallet_count,ava_qty,block_qty,current_quantity');
        windowGrid.setColTypes('ro,ro,ro,ro_bool,ron,ron,ron,ron');
        windowGrid.setNumberFormat("0,000", windowGrid.getColIndexById('pallet_count'), ",", ".");
        windowGrid.setNumberFormat("0,000", windowGrid.getColIndexById('current_quantity'), ",", ".");
        windowGrid.setNumberFormat("0,000", windowGrid.getColIndexById('ava_qty'), ",", ".");
        windowGrid.setNumberFormat("0,000", windowGrid.getColIndexById('block_qty'), ",", ".");
        windowGrid.setColSorting('str,str,str,str,int,int,int,int');
        windowGrid.attachFooter(['Total', gridUtils.spans.COLUMN, gridUtils.spans.COLUMN, gridUtils.spans.COLUMN,
            gridUtils.reducers.STATISTICS_TOTAL, gridUtils.reducers.STATISTICS_TOTAL, gridUtils.reducers.STATISTICS_TOTAL, gridUtils.reducers.STATISTICS_TOTAL],
          [TEXT_RIGHT_ALIGN + TEXT_BOLD, '', '', '', TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD, TEXT_RIGHT_ALIGN + TEXT_BOLD]);
        windowGrid.init();

        fetchWindowData(subplant, locationSubplant, motifSpec);

        function fetchWindowData(subplant, locationSubplant, motifSpec) {
          windowCell.progressOn();
          refreshPalletByMotifSummaryTimer.stop();
          WMSApi.stock.fetchSKUsAvailableForSalesByGroup(subplant, locationSubplant, [motifSpec])
            .then(SKUs => {
              const data = SKUs.map(SKU => ({
                id: `${SKU.location_subplant}_${SKU.production_subplant}_${SKU.quality}_${SKU.motif_id}_${SKU.size}_${SKU.shading}`,
                quality: SKU.quality,
                size: SKU.size,
                shading: SKU.shading,
                is_rimpil: SKU.is_rimpil,
                pallet_count: SKU.pallet_count,
                current_quantity: SKU.current_quantity,
                ava_qty: SKU.ava_qty,
                block_qty: SKU.block_qty,
              }));
              return {data};
            })
            .then(data => {
              const now = moment();
              windowGrid.clearAll();
              windowGrid.parse(data, 'js');
              windowToolbar.setItemText('timestamp', now.format());
              windowGrid.filterByAll();
              windowCell.progressOff();

              return {
                timestamp: now,
                data: data.data
              }
            })
            .then(({timestamp, data}) => {
              // update the one in the main grid
              palletsByMotifCell.getAttachedToolbar().setItemText('timestamp', timestamp.format());

              const totalEcoNormalQuantity = data.filter(sku => sku.quality === 'ECO' && !sku.is_rimpil)
                .map(val => val.current_quantity)
                .reduce((accum, value) => accum + value, 0);
              const totalExpNormalQuantity = data.filter(sku => sku.quality === 'EXP' && !sku.is_rimpil)
                .map(val => val.current_quantity)
                .reduce((accum, value) => accum + value, 0);
              const totalEcoRimpilQuantity = data.filter(sku => sku.quality === 'ECO' && sku.is_rimpil)
                .map(val => val.current_quantity)
                .reduce((accum, value) => accum + value, 0);
              const totalExpRimpilQuantity = data.filter(sku => sku.quality === 'EXP' && sku.is_rimpil)
                .map(val => val.current_quantity)
                .reduce((accum, value) => accum + value, 0);

              const rowId = `${locationSubplant}_${subplant}_${motifSpec}`;
              palletsByMotifGrid.cells(rowId, palletsByMotifGrid.getColIndexById('quantity_eco_normal'))
                .setValue(totalEcoNormalQuantity);
              palletsByMotifGrid.cells(rowId, palletsByMotifGrid.getColIndexById('quantity_exp_normal'))
                .setValue(totalExpNormalQuantity);
              palletsByMotifGrid.cells(rowId, palletsByMotifGrid.getColIndexById('quantity_subtotal_normal'))
                .setValue(totalExpNormalQuantity + totalEcoNormalQuantity);
              palletsByMotifGrid.cells(rowId, palletsByMotifGrid.getColIndexById('quantity_eco_rimpil'))
                .setValue(totalEcoRimpilQuantity);
              palletsByMotifGrid.cells(rowId, palletsByMotifGrid.getColIndexById('quantity_exp_rimpil'))
                .setValue(totalExpRimpilQuantity);
              palletsByMotifGrid.cells(rowId, palletsByMotifGrid.getColIndexById('quantity_subtotal_rimpil'))
                .setValue(totalExpRimpilQuantity + totalEcoRimpilQuantity);
              palletsByMotifGrid.cells(rowId, palletsByMotifGrid.getColIndexById('quantity_total'))
                .setValue(totalEcoNormalQuantity + totalExpNormalQuantity + totalEcoRimpilQuantity + totalExpRimpilQuantity);
              refreshPalletByMotifSummaryTimer.start();
            })
            .catch(error => {
              windowCell.progressOff();
              handleApiError(error);

              refreshPalletByMotifSummaryTimer.start();
            });
        }
      }

      function generateSummaryPDFWithRimpil(selectedSubplant, motifSpecs, withRimpil = false) {
        return WMSApi.stock.fetchMotifGroups(selectedSubplant, motifSpecs, withRimpil)
          .then(stockSummary => flattenSummaries(stockSummary, withRimpil))
          .then(({data, qualities}) => {
            // additional params
            const generatedAt = `Dibuat oleh [${USERID}] pada ${gridUtils.date.getCurrentLocaleDateTime()}`;
            const multiSubplant = AGGREGATE_MODES.includes(selectedSubplant);
            let title = multiSubplant ?
              `Ringkasan Stok Keramik Plant ${SUBPLANTS_PRODUCTION[0][0]}` :
              `Ringkasan Stok Keramik Subplant ${selectedSubplant}`;
            if (selectedSubplant === 'other') {
              title += ' - Plant Lain';
            } else if (selectedSubplant === 'all') {
              title += ' - Semua';
            } else if (selectedSubplant === 'local') {
              title += ' - Lokal';
            }


            var v_pageOrientasi = 'portrait';
            if (withRimpil) {
              v_pageOrientasi = 'landscape'; 
            }

            const dd = {
              // metadata
              info: {
                title: title
              },

              // page setup
              pageOrientation: v_pageOrientasi,
              pageSize: 'A4',
              pageMargins: [20, 60],

              header: {text: title, style: 'header', margin: [40, 20, 30, 40], alignment: 'center'},
              footer: (currentPage, pageCount) => ({
                columns: [
                  generatedAt,
                  // TODO i18n
                  {text: `Halaman ${currentPage} dari ${pageCount}`, alignment: 'right'}
                ],
                margin: [40, 20, 30, 40],
                fontSize: 10
              }),

              content: [],

              styles: {
                header: {
                  fontSize: 18,
                  bold: true,
                  margin: [0, 0, 0, 10]
                },
                table: {
                  margin: [0, 5, 0, 15]
                },
                tableHeader: {
                  bold: true,
                  fontSize: 12,
                  color: 'black'
                }
              },
            };

            // create table
            // setup header widths
            const withSubQuantity = qualities.length > 1 || withRimpil;
            const c_quantityHeaders = withRimpil ? (qualities.length * 4) : (qualities.length * 2);
            const headerWidths = [];
            headerWidths.push(35); // header for location_subplant
            if (multiSubplant) {
              headerWidths.push(35) // header for subplant
            }
            // add additional header widths
            // motif_dimension, motif_name
            headerWidths.push(45, '*');
            for (let i = 0; i <= c_quantityHeaders; i++) {
              headerWidths.push(45) // quantity_{quality}_{rimpil/normal}
            }

            const totalRows = withRimpil ? 3 : 2;
            const content = dd.content;
            const tableRoot = {
              style: 'table',
              table: {
                widths: headerWidths,
                headerRows: totalRows,
                body: [
                  [],
                  []
                ]
              }
            };


            content.push(tableRoot);
            const tableBody = tableRoot.table.body;
            // setup header
            tableBody[0].push({
              style: 'tableHeader',
              text: 'KD. GDG.',
              rowSpan: totalRows
            });
            if (multiSubplant) {
              tableBody[0].push({
                style: 'tableHeader',
                text: 'SUBP.',
                rowSpan: totalRows
              })
            }
            tableBody[0].push(
              {
                style: 'tableHeader',
                text: 'DIM.',
                rowSpan: totalRows
              },
              {
                style: 'tableHeader',
                text: 'MOTIF',
                rowSpan: totalRows
              },
            );

            // setup quantity header (first row)
            for (let i = 0; i < c_quantityHeaders; i++) {
              if (i === 0) {
                tableBody[0].push(
                  {
                    style: 'tableHeader',
                    text: 'QTY.',
                    alignment: qualities.length > 1 ? 'center' : 'right',
                    colSpan: c_quantityHeaders
                  },
                );
              } else {
                tableBody[0].push({});
              }
            }
            if (withSubQuantity) {
              tableBody[0].push(
                {
                  style: 'tableHeader',
                  text: 'SUBT. QTY.',
                  alignment: 'right',
                  rowSpan: totalRows
                }
              );
            }

            // setup second row
            const c_totalCol = tableBody[0].length;
            const col_startQuantity = multiSubplant ? 4 : 3;
            var qualities_n_block = [];
            qualities.forEach(quality => {
              qualities_n_block.push(quality);
            });
            qualities.forEach(quality => {
              qualities_n_block.push(`BL ${quality}`);
            });
            const c_qualities = qualities_n_block.length;
            for (let col_secondRow = 0, idx_quality = 0, col_rimpil = false; col_secondRow < c_totalCol; col_secondRow++) {
              if (multiSubplant && col_secondRow === 0) { // for subplant column, if multiple subplants
                tableBody[1].push('');
                continue;
              }
              // for subtotal
              if (col_secondRow === c_totalCol - 1) { // for last column
                if (withSubQuantity) {
                  tableBody[1].push('');
                } else {
                  tableBody[1].push({
                    style: 'tableHeader',
                    text: idx_quality < c_qualities ? qualities_n_block[idx_quality++] : 'N/A',
                    alignment: 'right'
                  })
                }
                continue;
              }

              if (col_secondRow < (multiSubplant ? 4 : 3)) { // for dimension and motif
                tableBody[1].push('');
              } else {
                if (withRimpil) {
                  tableBody[1].push(col_rimpil ? '' : { // for quality column
                    style: 'tableHeader',
                    text: idx_quality < c_qualities ? qualities_n_block[idx_quality++] : 'N/A',
                    alignment: 'center',
                    colSpan: 2
                  });
                  col_rimpil = !col_rimpil;
                } else {
                  tableBody[1].push({ // for quality column
                    style: 'tableHeader',
                    text: idx_quality < c_qualities ? qualities_n_block[idx_quality++] : 'N/A',
                    alignment: 'right'
                  });
                }
              }
            }

            // setup third row (only for rimpils)
            if (withRimpil) {
              const headerThirdRow = [];
              for (let col_thirdRow = 0, col_rimpil = false; col_thirdRow < c_totalCol; col_thirdRow++) {
                if (col_thirdRow < col_startQuantity || col_thirdRow === c_totalCol - 1) {
                  headerThirdRow.push({});
                  continue;
                }

                headerThirdRow.push({
                  style: 'tableHeader',
                  text: col_rimpil ? 'Rimpil' : 'Normal',
                  alignment: 'right'
                });
                col_rimpil = !col_rimpil;
              }

              tableBody.push(headerThirdRow);
            }

            // setup content
            const quantities = {total: 0};
            data.forEach(summary => {
              const tableRow = [];
              tableRow.push(summary.location_subplant);
              if (multiSubplant) {
                tableRow.push(summary.subplant);
              }
              tableRow.push(summary.motif_dimension, summary.motif_group_name);
              qualities.forEach(quality => { // quantity by quality
                if (withRimpil) {
                  const keyNormal = `quantity_${quality.toLowerCase()}_normal`;
                  const keyRimpil = `quantity_${quality.toLowerCase()}_rimpil`;

                  tableRow.push({
                    text: summary[keyNormal].toLocaleString(gridUtils.date.DEFAULT_LOCALE),
                    alignment: 'right'
                  });
                  tableRow.push({
                    text: summary[keyRimpil].toLocaleString(gridUtils.date.DEFAULT_LOCALE),
                    alignment: 'right'
                  });

                  if (!quantities.hasOwnProperty(keyNormal)) {
                    quantities[keyNormal] = summary[keyNormal];
                  } else {
                    quantities[keyNormal] += summary[keyNormal];
                  }
                  if (!quantities.hasOwnProperty(keyRimpil)) {
                    quantities[keyRimpil] = summary[keyRimpil];
                  } else {
                    quantities[keyRimpil] += summary[keyRimpil];
                  }
                  quantities.total += summary[keyRimpil] + summary[keyNormal];
                } else {
                  const key = `quantity_${quality.toLowerCase()}`;
                  tableRow.push({text: summary[key].toLocaleString(gridUtils.date.DEFAULT_LOCALE), alignment: 'right'});

                  if (!quantities.hasOwnProperty(key)) {
                    quantities[key] = summary[key];
                  } else {
                    quantities[key] += summary[key];
                  }
                  quantities.total += summary[key];
                }
              });

              // kolom block qty
              qualities.forEach(quality => { // quantity by quality
                if (withRimpil) {
                  const keyNormal = `quantity_block_${quality.toLowerCase()}_normal`;
                  const keyRimpil = `quantity_block_${quality.toLowerCase()}_rimpil`;

                  tableRow.push({
                    text: summary[keyNormal].toLocaleString(gridUtils.date.DEFAULT_LOCALE),
                    alignment: 'right'
                  });
                  tableRow.push({
                    text: summary[keyRimpil].toLocaleString(gridUtils.date.DEFAULT_LOCALE),
                    alignment: 'right'
                  });

                  if (!quantities.hasOwnProperty(keyNormal)) {
                    quantities[keyNormal] = summary[keyNormal];
                  } else {
                    quantities[keyNormal] += summary[keyNormal];
                  }
                  if (!quantities.hasOwnProperty(keyRimpil)) {
                    quantities[keyRimpil] = summary[keyRimpil];
                  } else {
                    quantities[keyRimpil] += summary[keyRimpil];
                  }
                  quantities.total += summary[keyRimpil] + summary[keyNormal];
                } else {
                  const key = `quantity_block_${quality.toLowerCase()}`;
                  tableRow.push({text: summary[key].toLocaleString(gridUtils.date.DEFAULT_LOCALE), alignment: 'right'});

                  if (!quantities.hasOwnProperty(key)) {
                    quantities[key] = summary[key];
                  } else {
                    quantities[key] += summary[key];
                  }
                  quantities.total += summary[key];
                }
              });

              if (withSubQuantity) { // subtotal
                tableRow.push({
                  text: summary.quantity_subtotal.toLocaleString(gridUtils.date.DEFAULT_LOCALE),
                  alignment: 'right',
                  bold: true
                })
              }

              tableBody.push(tableRow);
            });

            // setup footer.
            const footer = [];
            tableBody.push(footer);

            footer.push({text: 'TOTAL', alignment: 'right', style: 'tableHeader', colSpan: col_startQuantity});
            for (let col_footer = 1; col_footer < col_startQuantity; col_footer++) {
              footer.push({});
            }
            qualities.forEach(quality => {
              if (withRimpil) {
                const keyNormal = `quantity_${quality.toLowerCase()}_normal`;
                const keyRimpil = `quantity_${quality.toLowerCase()}_rimpil`;

                footer.push({
                  text: quantities[keyNormal].toLocaleString(gridUtils.date.DEFAULT_LOCALE),
                  alignment: 'right',
                  bold: true
                });
                footer.push({
                  text: quantities[keyRimpil].toLocaleString(gridUtils.date.DEFAULT_LOCALE),
                  alignment: 'right',
                  bold: true
                });
              } else {
                const key = `quantity_${quality.toLowerCase()}`;
                footer.push({
                  text: quantities[key].toLocaleString(gridUtils.date.DEFAULT_LOCALE),
                  alignment: 'right',
                  bold: true
                });
              }
            });

            qualities.forEach(quality => {
              if (withRimpil) {
                const keyNormal = `quantity_block_${quality.toLowerCase()}_normal`;
                const keyRimpil = `quantity_block_${quality.toLowerCase()}_rimpil`;

                footer.push({
                  text: quantities[keyNormal].toLocaleString(gridUtils.date.DEFAULT_LOCALE),
                  alignment: 'right',
                  bold: true
                });
                footer.push({
                  text: quantities[keyRimpil].toLocaleString(gridUtils.date.DEFAULT_LOCALE),
                  alignment: 'right',
                  bold: true
                });
              } else {
                const key = `quantity_block_${quality.toLowerCase()}`;
                footer.push({
                  text: quantities[key].toLocaleString(gridUtils.date.DEFAULT_LOCALE),
                  alignment: 'right',
                  bold: true
                });
              }
            });

            if (withSubQuantity) {
              footer.push({
                text: quantities.total.toLocaleString(gridUtils.date.DEFAULT_LOCALE),
                alignment: 'right',
                bold: true
              });
            }
            // end of create table
            return dd;
          });
      }

      function generateSummaryCSV(selectedSubplant, motifSpecs, withRimpil = false) {
        return WMSApi.stock.fetchMotifGroups(selectedSubplant, motifSpecs, withRimpil)
          .then(stockSummary => flattenSummaries(stockSummary, withRimpil))
          .then(({data, qualities}) => {
            const multiSubplant = selectedSubplant === 'all' || selectedSubplant === 'other';
            const withSubQuantity = qualities.length > 1 || withRimpil;

            const headers = [];
            headers.push('location_subplant');
            if (multiSubplant) {
              headers.push('subplant');
            }
            headers.push('motif_dimension', 'motif_group_name');
            qualities.forEach(quality => {
              if (withRimpil) {
                headers.push(`quantity_${quality.toLowerCase()}_normal`);
                headers.push(`quantity_${quality.toLowerCase()}_rimpil`);
              } else {
                headers.push(`quantity_${quality.toLowerCase()}`);
              }
            });

            qualities.forEach(quality => {
              if (withRimpil) {
                headers.push(`quantity_block_${quality.toLowerCase()}_normal`);
                headers.push(`quantity_block_${quality.toLowerCase()}_rimpil`);
              } else {
                headers.push(`quantity_block_${quality.toLowerCase()}`);
              }
            });
            
            if (withSubQuantity) {
              headers.push('quantity_subtotal');
            }

            let title = multiSubplant ?
              `Ringkasan Stok Keramik Plant ${SUBPLANTS_PRODUCTION[0][0]}` :
              `Ringkasan Stok Keramik Subplant ${selectedSubplant}`;
            if (selectedSubplant === 'other') {
              title += ' - Plant Lain';
            }
            const filename = moment().format('YYYY-MM-DD') +
              ' - ' + title;
            const dump = [];
            data.forEach(row => {
              const dumpRow = [];
              headers.forEach(header => {
                const val = row[header];
                dumpRow.push(val instanceof Number ? val : `"${val}"`);
              });
              dump.push(dumpRow);
            });
            gridUtils.downloadCSV(filename, dump, headers);
          });
      }

      function flattenSummaries(stockSummary, withRimpil) {
        const qualities = stockSummary.qualities;
        const summaries = stockSummary.data.map(summary => {
          const row = {
            location_subplant: summary.location_subplant,
            subplant: summary.production_subplant,
            motif_group_id: summary.motif_group_id,
            motif_dimension: summary.motif_dimension,
            motif_group_name: summary.motif_group_name
          };

          let quantity_total = 0;
          qualities.forEach(quality => {
            const quantity = summary.quantity[quality];
            const block_qty = summary.block_qty[quality];
            if (withRimpil) {
              row[`quantity_${quality.toLowerCase()}_normal`] = quantity.normal - block_qty.normal;
              row[`quantity_${quality.toLowerCase()}_rimpil`] = quantity.rimpil - block_qty.rimpil;
              row[`quantity_block_${quality.toLowerCase()}_normal`] = block_qty.normal;
              row[`quantity_block_${quality.toLowerCase()}_rimpil`] = block_qty.rimpil;
              quantity_total += quantity.normal + quantity.rimpil;
            } else {
              row[`quantity_${quality.toLowerCase()}`] = quantity - block_qty;
              row[`quantity_block_${quality.toLowerCase()}`] = block_qty;
              quantity_total += quantity;
            }
          });
          row['quantity_subtotal'] = quantity_total;
          return row
        });

        return {data: summaries, qualities}
      }

      // setup auto refresh
      const DEFAULT_INTERVAL_LENGTH = 5 * 60 * 1000; // 5 mins
      const refreshPalletByMotifSummaryTimer = new Timer(() => {
        fetchMotifGroupsAvailableForSales(palletsByMotifSelectedSubplant);
      }, DEFAULT_INTERVAL_LENGTH);

      return {
        doOnLoad,
        refreshPalletByMotifSummaryInterval: refreshPalletByMotifSummaryTimer
      }
    }(moment, WMSApi, gridUtils, pdfMake, handleApiError, Timer)
  </script>
</head>
<body onload="dashboard.doOnLoad();">

</body>
</html>
