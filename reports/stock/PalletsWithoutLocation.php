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
  $allowedRoles = RoleAcl::palletsWithoutLocation();
  $authorized = UserRole::hasAnyRole($allowedRoles);
}

if (!$authorized) {
  $errorMessage = 'Anda tidak punya akses ke data palet tanpa lokasi!';
}

if (isset($errorMessage)) {
  die ($errorMessage);
}
?>
<!DOCTYPE HTML>
<html lang="id">
<head>
  <title>Daftar Palet per Area/Baris</title>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
  <meta http-equiv="X-UA-Compatible" content="IE=edge"/>

  <link rel="stylesheet" type="text/css" href="../../assets/libs/dhtmlx/dhtmlx.css"/>
  <link rel="stylesheet" type="text/css" href="../../assets/fonts/font_roboto/roboto.css"/>
  <link rel="stylesheet" type="text/css" href="../../assets/fonts/font_awesome/css/font-awesome.min.css"/>
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

  <script src="../../assets/libs/dhtmlx/dhtmlx.js"></script>
  <script src="../../assets/libs/axios/axios.min.js"></script>
  <script src="../../assets/libs/moment/moment-with-locales.min.js"></script>
  <script src="../../assets/libs/pdfmake/pdfmake.min.js"></script>
  <script src="../../assets/libs/pdfmake/vfs_fonts.js"></script>
  <script src="../../assets/libs/js-cookie/js.cookie.min.js"></script>

  <script src="../../assets/js/date-utils.js"></script>
  <script src="../../assets/js/WMSApi-20190711-01.js"></script>
  <script src="../../assets/js/grid-utils-20190704-01.js"></script>
  <script src="../../assets/js/grid-custom-types-20190704-01.js"></script>
  <script>
    dhx.ajax.cache = true; // fix barcode not showing.
    const report = (function (moment, gridUtils, Cookies, WMSApi) {
      WMSApi.setBaseUrl('../../api');

      const CELL_SELECT_SUBPLANT_FORM = 'a';
      const CELL_PALLETS_GRID = 'b';
      const USERID = '<?= $user->gua_kode ?>';

      const STYLES = gridUtils.styles;
      const FILTERS = gridUtils.headerFilters;

      let grid_pallets, layout_root;
      let form_selectSubplant;

      let windows;
      function doOnLoad() {
        layout_root = new dhtmlXLayoutObject({
          parent: document.body,
          pattern: '2E',
          cells: [
            {id: CELL_SELECT_SUBPLANT_FORM, text: 'Pilih Subplant', height: 90},
            {id: CELL_PALLETS_GRID, text: 'Daftar Palet'}
          ]
        });

        windows = new dhtmlXWindows();
        // setup selectLine part
        form_selectSubplant = layout_root.cells(CELL_SELECT_SUBPLANT_FORM).attachForm([
          {type: "settings", position: "label-left", labelWidth: 70, inputWidth: 160},
          {
            type: 'combo',
            name: 'subplant',
            label: 'Subplant',
            inputWidth: 80,
            required: true,
            readonly: true
          },
          {type: 'newcolumn'},
          {type: 'button', offsetLeft: 30, name: 'search', value: 'Dapatkan Palet'}
        ]);
        form_selectSubplant.attachEvent('onButtonClick', function (id) {
          if (id === 'search') {
            const subplant = form_selectSubplant.getItemValue('subplant');
            fetchPallets(subplant)
          }
        });
        const combo_subplant = form_selectSubplant.getCombo('subplant');
        <?php
        $availableSubplants = $user->gua_subplant_handover;
        if (count($availableSubplants) > 1): ?>
        combo_subplant.addOption('all', 'Semua');
        <?php endif; ?>
        <?php foreach($availableSubplants as $subplant): ?>
        combo_subplant.addOption('<?= $subplant ?>', '<?= $subplant ?>');
        <?php endforeach ?>
        combo_subplant.selectOption(0);

        function openPDFDocument(blob, title) {
          const pdfWin = windows.createWindow("w2", 0, 0, 800, 450);
          pdfWin.centerOnScreen();
          pdfWin.setText(title);
          pdfWin.button("park").hide();
          pdfWin.setModal(true);

          const fileName = title + '.pdf';
          const file = new File([blob], fileName, {type: 'application/pdf', lastModified: Date.now()});
          let fileURL = URL.createObjectURL(file);

          pdfWin.attachURL(fileURL);
          pdfWin.attachEvent('onClose', () => {
            if (fileURL) {
              URL.revokeObjectURL(fileURL);
              blob = null;
              fileURL = null;
            }
            return true
          });
          return pdfWin
        }

        const toolbar_pallets = layout_root.cells(CELL_PALLETS_GRID).attachToolbar({
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
        toolbar_pallets.attachEvent('onClick', itemId => {
          const subplant = form_selectSubplant.getItemValue('subplant');
          let title = `Daftar Palet Tanpa Lokasi`;
          if (subplant !== 'all') {
            title += ` - Subplant ${subplant}`;
          }

          const pdfWidths = '30,60,120,80,45,35,*,70,30,40,30,60,40,120';
          const cell = layout_root.cells(CELL_PALLETS_GRID);
          switch (itemId) {
            case 'refresh':
              fetchPallets(subplant);
              break;
            case 'clear_filters':
              gridUtils.clearAllGridFilters(grid_pallets);
              break;
            case 'print':
              cell.progressOn();
              gridUtils.generateFilteredPdf(grid_pallets, title, USERID, pdfWidths)
                .getBlob(blob => {
                  cell.progressOff();
                  openPDFDocument(blob, title);
                }, { autoPrint: true });
              break;
            case 'export_csv':
              const csvTitle = `${moment().format('YYYY-MM-DD')} - ${title}`;
              gridUtils.downloadFilteredCSV(grid_pallets, csvTitle);
              break;
            case 'export_pdf':
              const pdfTitle = `${moment().format('YYYY-MM-DD')} - ${title}`;
              gridUtils.generateFilteredPdf(grid_pallets, title, USERID, pdfWidths)
                .getBlob(blob => {
                  cell.progressOff();
                  openPDFDocument(blob, pdfTitle);
                });
              break;
          }
        });

        grid_pallets = layout_root.cells(CELL_PALLETS_GRID).attachGrid();
        grid_pallets.setHeader("SUBP.,NO. PALET,KD. MOTIF,DIM.,QLTY.,MOTIF,TGL. BUAT,SIZE,SHADING,LINE,REGU,SHIFT,QTY. AWAL,QTY. KINI", null,
          ['', '', '', '', '', '', '', '', '', STYLES.TEXT_RIGHT_ALIGN, '', STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_RIGHT_ALIGN, STYLES.TEXT_RIGHT_ALIGN]);
        grid_pallets.setColumnIds('subplant,pallet_no,motif_id,motif_dimension,quality,motif_name,created_at,size,shading,line,creator_group,creator_shift,initial_quantity,current_quantity');
        grid_pallets.setColTypes('rotxt,rotxt,rotxt,rotxt,rotxt,rotxt,ro_date,rotxt,rotxt,ron,rotxt,rotxt,ron,ron');
        grid_pallets.setInitWidths("45,120,0,50,50,*,80,60,70,50,50,50,80,80");
        grid_pallets.setColAlign("left,left,left,left,left,left,left,left,left,left,left,right,left,left,right,right");
        grid_pallets.setColSorting("str,str,str,str,str,str,str,str,str,str,int,str,str,int,int");
        grid_pallets.attachHeader([
          FILTERS.SELECT, FILTERS.TEXT, FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT, FILTERS.TEXT,
          FILTERS.TEXT, FILTERS.SELECT, FILTERS.SELECT,
          FILTERS.SELECT, FILTERS.SELECT, FILTERS.SELECT,
          FILTERS.NUMERIC, FILTERS.NUMERIC]);
        grid_pallets.attachFooter(['', 'Total', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', '#cspan', gridUtils.reducers.STATISTICS_COUNT, gridUtils.reducers.STATISTICS_TOTAL]
          , ['', STYLES.TEXT_RIGHT_ALIGN + STYLES.TEXT_BOLD, '', '', '', '', '', '', '', '', '', '', STYLES.TEXT_RIGHT_ALIGN + STYLES.TEXT_BOLD, STYLES.TEXT_RIGHT_ALIGN + STYLES.TEXT_BOLD]);

        grid_pallets.setColumnHidden(grid_pallets.getColIndexById('motif_id'), true);
        grid_pallets.setNumberFormat("0,000", grid_pallets.getColIndexById('initial_quantity'), ",", ".");
        grid_pallets.setNumberFormat("0,000", grid_pallets.getColIndexById('current_quantity'), ",", ".");
        grid_pallets.attachEvent("onXLS", function () {
          layout_root.cells(CELL_PALLETS_GRID).progressOn();
        });
        grid_pallets.attachEvent("onXLE", function () {
          layout_root.cells(CELL_PALLETS_GRID).progressOff()
        });
        grid_pallets.enableSmartRendering(true, 100);
        grid_pallets.init();
      }

      function fetchPallets(subplant) {
        layout_root.cells(CELL_PALLETS_GRID).progressOn();
        return WMSApi.stock.fetchPalletsWithoutLocation(subplant)
          .then(pallets => ({
            data: pallets.map(pallet => ({
              id: pallet.pallet_no,
              subplant: pallet.subplant,
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
              initial_quantity: pallet.initial_quantity,
              current_quantity: pallet.current_quantity
            }))
          }))
          .then(data => {
            gridUtils.clearAllGridFilters(grid_pallets);
            grid_pallets.clearAll();
            grid_pallets.setColumnHidden(grid_pallets.getColIndexById('subplant'), subplant !== 'all');
            grid_pallets.parse(data, 'js');

            layout_root.cells(CELL_PALLETS_GRID).progressOff();

            // show no pallet notification
            if (data.data.length === 0) {
              let message = 'Tidak ada palet tanpa lokasi ';
              message += subplant === 'all' ? 'pada Plant <?= PlantIdHelper::getCurrentPlant() ?>.' : `pada Subplant ${subplant}.`;
              dhtmlx.message(message)
            }
          })
          .catch(error => {
            layout_root.cells(CELL_PALLETS_GRID).progressOff();
            console.error(error);
            dhtmlx.alert({
              type: "alert-warning",
              text: error instanceof Object ? error.message : error,
              title: 'Error'
            })
          })
      }

      return {doOnLoad}
    })(moment, gridUtils, Cookies, WMSApi);
  </script>
</head>
<body onload="report.doOnLoad()">

</body>
</html>
