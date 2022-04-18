<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

use Utils\Env;
use Utils\FileLog;
use Utils\Mail;

function reduce_sum($carry, $item)
{
    return $carry + $item;
}

try {
    $fileLogger = FileLog::getInstance('report');
} catch (Exception $e) {
    // use PHP's own error log
    error_log('Cannot open report log file! Reason: ' . $e->getMessage());
    exit;
}

// for pallets with no location
const NO_LOCATION_AREA_CODE = 'XX';
const NO_LOCATION_AREA_NAME = 'TIDAK ADA LOKASI';
const NO_LOCATION_LINE_COUNT = 1;

$currentPlant = PlantIdHelper::getCurrentPlant();
try {
    $db = PostgresqlDatabase::getInstance();

    $queryArea = '
SELECT 
  plan_kode AS location_subplant,
  ket_area AS location_area_name,
  kd_area AS location_area_no,
  kd_baris AS line_count 
FROM inv_master_area ima';
    $cursorArea = $db->rawQuery($queryArea);

    $locationInfo = array();
    $productionPlants = array();
    while ($row = pg_fetch_assoc($cursorArea)) {
        if (!isset($locationInfo[$row['location_subplant']])) {
            $locationInfo[$row['location_subplant']] = array();
        }

        $lineCount = intval($row['line_count']);
        $locationInfo[$row['location_subplant']][$row['location_area_no']] = array(
            'name' => $row['location_area_name'],
            'line_count' => $lineCount
        );
    }

    $fileLogger->debug('Start rimpil refresh');
    $queryRefreshRimpil = 'SELECT mv_refresh(\'rimpil_by_motif_size_shading\')';
    $cursorRefreshRimpil = $db->rawQuery($queryRefreshRimpil);

    $fileLogger->debug('Start data refresh (with location)');
    $queryRefresh = 'SELECT mv_refresh(\'pallets_with_location_age_and_rimpil\')';
    $cursorRefresh = $db->rawQuery($queryRefresh);

    assert(pg_num_rows($cursorRefresh) === 1);
    $rowRefresh = pg_fetch_row($cursorRefresh, null, PGSQL_NUM);

    $queryData = 'SELECT pallets_with_location_age_and_rimpil.*, item.group_nama AS type_produksi 
        FROM pallets_with_location_age_and_rimpil 
        JOIN item ON item.item_kode = pallets_with_location_age_and_rimpil.motif_id
        WHERE current_quantity > 0';
    $cursorData = $db->rawQuery($queryData);

    $rawData = array();
    $palletsByAreaStatistics = array();
    $fileLogger->debug('Start data fetch (with location)');

    while ($row = pg_fetch_assoc($cursorData)) {
        // do transformation.
        $row['current_quantity'] = intval($row['current_quantity']);
        $row['creator_group'] = isset($row['creator_group']) ? $row['creator_group'] : 'N/A';
        $row['creator_shift'] = isset($row['creator_shift']) ? intval($row['creator_shift']) : 'N/A';
        $row['pallet_month_category'] = isset($row['pallet_month_category']) ? $row['pallet_month_category'] : 'N/A';
        $row['line'] = isset($row['line']) ? intval($row['line']) : 'N/A';
        $row['pallet_age'] = intval($row['pallet_age']);
        $row['is_rimpil'] = QueryResultConverter::toBool($row['is_rimpil']);
        $row['is_blocked'] = QueryResultConverter::toBool($row['is_blocked']);

        // collect to statistics
        if (!isset($palletsByAreaStatistics[$row['location_subplant']])) {
            $palletsByAreaStatistics[$row['location_subplant']] = array();
        }
        if (!isset($palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']])) {
            $palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']] = array(
                'name' => $row['location_area_name'],
                'rimpil_count' => $row['is_rimpil'] ? 1 : 0,
                'full_count' => $row['is_rimpil'] ? 0 : 1,
                'age_count' => array(),
                'line_quantity' => array()
            );
        } else {
            if ($row['is_rimpil']) {
                $palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']]['rimpil_count']++;
            } else {
                $palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']]['full_count']++;
            }
        }

        $currentLine = intval($row['location_line_no']);
        if (!isset($palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']]['age_count'][$currentLine])) {
            $palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']]['age_count'][$currentLine] = array();
        }
        if (!isset($palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']]['age_count_total'])) {
            $palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']]['age_count_total'] = array();
        }

        // total quantity per line
        if (!isset($palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']]['line_quantity'][$currentLine])) {
            $palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']]['line_quantity'][$currentLine] = $row['current_quantity'];
        } else {
            $palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']]['line_quantity'][$currentLine] += $row['current_quantity'];
        }

        // age count for individual line
        if (!isset($palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']]['age_count'][$currentLine][$row['pallet_age_category']])) {
            $palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']]['age_count'][$currentLine][$row['pallet_age_category']] = 1;
        } else {
            $palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']]['age_count'][$currentLine][$row['pallet_age_category']]++;
        }

        // age count total
        if (!isset($palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']]['age_count_total'][$row['pallet_age_category']])) {
            $palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']]['age_count_total'][$row['pallet_age_category']] = 1;
        } else {
            $palletsByAreaStatistics[$row['location_subplant']][$row['location_area_no']]['age_count_total'][$row['pallet_age_category']]++;
        }

        $rawData[] = $row;
    }

    // get the data without location
    $fileLogger->debug('Start data refresh (without location)');
    $queryRefreshNoLocation = 'SELECT mv_refresh(\'pallets_without_location_age_and_rimpil\')';
    $cursorRefreshNoLocation = $db->rawQuery($queryRefreshNoLocation);

    assert(pg_num_rows($cursorRefreshNoLocation) === 1);
    $rowRefreshNoLocation = pg_fetch_row($cursorRefreshNoLocation, null, PGSQL_NUM);
    $dataDateTimeNoLocation = $rowRefreshNoLocation[0];
    $dataDateTime = DateTime::createFromFormat(PostgresqlDatabase::PGSQL_TIMESTAMP_FORMAT, $dataDateTimeNoLocation)
        ->format(PostgresqlDatabase::PGSQL_DATETIME_LOCAL_FORMAT);

    $queryData = 'SELECT pallets_without_location_age_and_rimpil.*, item.group_nama AS type_produksi 
        FROM pallets_without_location_age_and_rimpil
        JOIN item ON item.item_kode = pallets_without_location_age_and_rimpil.motif_id';
    $cursorData = $db->rawQuery($queryData);

    // collect result
    $rawDataNoLocation = array();
    $fileLogger->debug('Start data fetch (without location)');

    $productionSubplants = array();
    while ($row = pg_fetch_assoc($cursorData)) {
        // do transformation.
        $row['current_quantity'] = intval($row['current_quantity']);
        $row['creator_group'] = isset($row['creator_group']) ? $row['creator_group'] : 'N/A';
        $row['creator_shift'] = isset($row['creator_shift']) ? intval($row['creator_shift']) : 'N/A';
        $row['pallet_month_category'] = isset($row['pallet_month_category']) ? $row['pallet_month_category'] : 'N/A';
        $row['line'] = isset($row['line']) ? intval($row['line']) : 'N/A';
        $row['pallet_age'] = intval($row['pallet_age']);
        $row['is_rimpil'] = QueryResultConverter::toBool($row['is_rimpil']);
        $row['is_blocked'] = QueryResultConverter::toBool($row['is_blocked']);

        $productionSubplants[$row['production_subplant']] = true;

        // collect to statistics
        if (!isset($palletsByAreaStatistics[$row['production_subplant']])) {
            $palletsByAreaStatistics[$row['production_subplant']] = array();
        }
        if (!isset($palletsByAreaStatistics[$row['production_subplant']][NO_LOCATION_AREA_CODE])) {
            $palletsByAreaStatistics[$row['production_subplant']][NO_LOCATION_AREA_CODE] = array(
                'name' => NO_LOCATION_AREA_NAME,
                'line_count' => NO_LOCATION_LINE_COUNT,
                'line_quantity' => array(
                    NO_LOCATION_LINE_COUNT => 0
                ),
                'line_quantity_total' => 0,
                'rimpil_count' => $row['is_rimpil'] ? 1 : 0,
                'full_count' => $row['is_rimpil'] ? 0 : 1,
                'age_count' => array(
                    NO_LOCATION_LINE_COUNT => array()
                ),
                'age_count_total' => array()
            );
        } else {
            if ($row['is_rimpil']) {
                $palletsByAreaStatistics[$row['production_subplant']][NO_LOCATION_AREA_CODE]['rimpil_count']++;
            } else {
                $palletsByAreaStatistics[$row['production_subplant']][NO_LOCATION_AREA_CODE]['full_count']++;
            }
        }

        // total quantity
        $palletsByAreaStatistics[$row['production_subplant']][NO_LOCATION_AREA_CODE]['line_quantity'][NO_LOCATION_LINE_COUNT] +=
            $row['current_quantity'];
        $palletsByAreaStatistics[$row['production_subplant']][NO_LOCATION_AREA_CODE]['line_quantity_total'] +=
            $row['current_quantity'];

        // age count for individual line
        if (!isset($palletsByAreaStatistics[$row['production_subplant']][NO_LOCATION_AREA_CODE]['age_count'][NO_LOCATION_LINE_COUNT][$row['pallet_age_category']])) {
            $palletsByAreaStatistics[$row['production_subplant']][NO_LOCATION_AREA_CODE]['age_count'][NO_LOCATION_LINE_COUNT][$row['pallet_age_category']] = 1;
        } else {
            $palletsByAreaStatistics[$row['production_subplant']][NO_LOCATION_AREA_CODE]['age_count'][NO_LOCATION_LINE_COUNT][$row['pallet_age_category']]++;
        }

        // age count total
        if (!isset($palletsByAreaStatistics[$row['production_subplant']][NO_LOCATION_AREA_CODE]['age_count_total'][$row['pallet_age_category']])) {
            $palletsByAreaStatistics[$row['production_subplant']][NO_LOCATION_AREA_CODE]['age_count_total'][$row['pallet_age_category']] = 1;
        } else {
            $palletsByAreaStatistics[$row['production_subplant']][NO_LOCATION_AREA_CODE]['age_count_total'][$row['pallet_age_category']]++;
        }

        $rawDataNoLocation[] = $row;
    }
    $productionSubplants = array_keys($productionSubplants);
    $db->close();

    // NOTE: any change to the view need to be reflected here.
    $ageCategories = array('Very Fast', 'Fast', 'Medium', 'Slow', 'Dead Stock');

    // set zeros for age statistics
    /* quick structure breakdown of $palletsByAreaStatistics:
        - subplant (array, with string - subplant - as key.)
            - area (array, with string - areaCode - as key)
                - name (string, name of the area)
                - rimpil_count (number of rimpil pallets, as per the rimpil definition in the materialized view.)
                - full_count (number of non-rimpil pallets)
                - line_count (int, number of lines in the area)
                - line_quantity (array, quantity per line)
                - age_count (array, details of pallets that fall within a certain age category, with int - lineNo - as key)
                    - category 1 ... n
                    - Total
                - age_count_total
                    - category 1 ... n
                    - Total
    */
    foreach ($locationInfo as $locationSubplant => $area) {
        if (!isset($palletsByAreaStatistics[$locationSubplant])) {
            $palletsByAreaStatistics[$locationSubplant] = array();
        }
        foreach ($area as $areaNo => $areaDetails) {
            $lineCount = $locationInfo[$locationSubplant][$areaNo]['line_count'];

            if (!isset($palletsByAreaStatistics[$locationSubplant][$areaNo])) {
                $palletsByAreaStatistics[$locationSubplant][$areaNo] = array(
                    'name' => $areaDetails['name'],
                    'line_count' => $lineCount,
                    'rimpil_count' => 0,
                    'full_count' => 0,
                    'line_quantity' => array(),
                    'age_count' => array(),
                    'age_count_total' => array()
                );
            }

            // age count
            for ($line = 1; $line <= $lineCount; $line++) {
                if (!isset($palletsByAreaStatistics[$locationSubplant][$areaNo]['age_count'][$line])) {
                    $palletsByAreaStatistics[$locationSubplant][$areaNo]['age_count'][$line] = array();
                }

                foreach ($ageCategories as $category) {
                    if (!isset($palletsByAreaStatistics[$locationSubplant][$areaNo]['age_count'][$line][$category])) {
                        $palletsByAreaStatistics[$locationSubplant][$areaNo]['age_count'][$line][$category] = 0;
                    }
                }
                $palletsByAreaStatistics[$locationSubplant][$areaNo]['age_count'][$line]['Total'] =
                    array_reduce($palletsByAreaStatistics[$locationSubplant][$areaNo]['age_count'][$line], 'reduce_sum', 0);
            }
            ksort($palletsByAreaStatistics[$locationSubplant][$areaNo]['age_count']); // ensure the line is sorted.

            foreach ($ageCategories as $category) {
                if (!isset($palletsByAreaStatistics[$locationSubplant][$areaNo]['age_count_total'][$category])) {
                    $palletsByAreaStatistics[$locationSubplant][$areaNo]['age_count_total'][$category] = 0;
                }
            }
            $palletsByAreaStatistics[$locationSubplant][$areaNo]['age_count_total']['Total'] =
                array_reduce($palletsByAreaStatistics[$locationSubplant][$areaNo]['age_count_total'], 'reduce_sum', 0);

            // line quantity
            for ($line = 1; $line <= $lineCount; $line++) {
                if (!isset($palletsByAreaStatistics[$locationSubplant][$areaNo]['line_quantity'][$line])) {
                    $palletsByAreaStatistics[$locationSubplant][$areaNo]['line_quantity'][$line] = 0;
                }
            }
            ksort($palletsByAreaStatistics[$locationSubplant][$areaNo]['line_quantity']); // ensure the line is sorted.
            $palletsByAreaStatistics[$locationSubplant][$areaNo]['line_quantity_total'] =
                array_reduce($palletsByAreaStatistics[$locationSubplant][$areaNo]['line_quantity'], 'reduce_sum', 0);
        }

        // sort all area for the particular subplant
        uksort($palletsByAreaStatistics[$locationSubplant], function ($a, $b) {
            return strnatcmp($a, $b);
        });
    }

    // do the same thing for every visible production subplants.
    foreach ($productionSubplants as $subplant) {
        if (!isset($palletsByAreaStatistics[$subplant])) {
            $palletsByAreaStatistics[$subplant] = array();
        }

        if (!isset($palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE])) {
            $palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE] = array(
                'name' => NO_LOCATION_AREA_NAME,
                'line_count' => NO_LOCATION_LINE_COUNT,
                'rimpil_count' => 0,
                'full_count' => 0,
                'line_quantity' => array(),
                'age_count' => array(),
                'age_count_total' => array()
            );
        }

        // age count
        if (!isset($palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE]['age_count'][NO_LOCATION_LINE_COUNT])) {
            $palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE]['age_count'][NO_LOCATION_LINE_COUNT] = array();
        }

        foreach ($ageCategories as $category) {
            if (!isset($palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE]['age_count'][NO_LOCATION_LINE_COUNT][$category])) {
                $palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE]['age_count'][NO_LOCATION_LINE_COUNT][$category] = 0;
            }
        }
        $palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE]['age_count'][NO_LOCATION_LINE_COUNT]['Total'] =
            array_reduce($palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE]['age_count'][NO_LOCATION_LINE_COUNT], 'reduce_sum', 0);

        foreach ($ageCategories as $category) {
            if (!isset($palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE]['age_count_total'][$category])) {
                $palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE]['age_count_total'][$category] = 0;
            }
        }
        $palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE]['age_count_total']['Total'] =
            array_reduce($palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE]['age_count_total'], 'reduce_sum', 0);

        // line quantity
        if (!isset($palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE]['line_quantity'][NO_LOCATION_LINE_COUNT])) {
            $palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE]['line_quantity'][NO_LOCATION_LINE_COUNT] = 0;
        }
        ksort($palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE]['line_quantity']); // ensure the line is sorted.
        $palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE]['line_quantity_total'] =
            array_reduce($palletsByAreaStatistics[$subplant][NO_LOCATION_AREA_CODE]['line_quantity'], 'reduce_sum', 0);

        // sort all area for the particular subplant
        uksort($palletsByAreaStatistics[$subplant], function ($a, $b) {
            return strnatcmp($a, $b);
        });
    }

    // sort by subplant
    ksort($palletsByAreaStatistics);
    $fileLogger->debug('Finish Grouping');

    // generate Excel report
    /* structure of the Excel file:
      1. Sheet 1: details, containing all raw data.
      2. Sheet 2: Daily, containing rimpil vs full pallet count, grouped by area.
      3. Sheet 3..n: pallets grouped by age, by line.
      */
    $fileLogger->debug('[Excel] Start create Excel');
    $phpExcel = new PHPExcel();
    $excelTitle = date('Y-m-d') . ' - Laporan Harian Rimpil dan Aging Palet Plant ' . PlantIdHelper::getCurrentPlant();
    $phpExcel->getProperties()
        ->setCreator(Env::get('MAIL_USER_DISPLAY'))
        ->setLastModifiedBy(Env::get('MAIL_USER_DISPLAY'))
        ->setTitle($excelTitle);

    // generate sheet 1: details.
    $fileLogger->debug('[Excel] Start create Sheet 1: Details (With Location)');
    $phpExcel->setActiveSheetIndex('0');
    $detailsSheet = $phpExcel->getActiveSheet();
    $detailsSheet->setTitle('Details (Location)');

    // set header
    $c_rawData = count($rawData);

    // edge case: no data
    if ($c_rawData === 0) {
        $fileLogger->notice('[Details w/ Location] No data!');
        $detailsSheet->fromArray(array('TIDAK ADA DATA'));
    } else {
        $fileLogger->debug('[Details w/ Location] Start Dump');
        $detailsDump = array();
        $detailsColumnHeaders = array_keys($rawData[0]);
        $detailsDump[] = $detailsColumnHeaders;

        // set values
        $c_detailsColumnHeaders = count($detailsColumnHeaders);
        for ($row = 0; $row < $c_rawData; $row++) {
            $dataRow = array();
            for ($currentCol = 0; $currentCol < $c_detailsColumnHeaders; $currentCol++) {
                $var = $rawData[$row][$detailsColumnHeaders[$currentCol]];
                if (is_bool($var)) {
                    $dataRow[] = $var ? 'TRUE' : 'FALSE';
                } else {
                    $dataRow[] = $var;
                }
            }

            $detailsDump[] = $dataRow;
        }
        $fileLogger->debug('[Details w/ Location] Dump Done');
        $fileLogger->debug('[Details w/ Location] Writing to sheet');
        $detailsSheet->fromArray($detailsDump);
        unset($detailsDump);
        unset($detailsColumnHeaders);

        // autosize every column
        $it_detailsCell = $detailsSheet->getRowIterator()->current()->getCellIterator();
        $it_detailsCell->setIterateOnlyExistingCells(true);
        foreach ($it_detailsCell as $cell) {
            $detailsSheet->getColumnDimension($cell->getColumn())->setAutoSize(true);
        }

        // freeze top row
        $detailsSheet->freezePane('A2');
    }

    // generate sheet 2: details (w/o location).
    $fileLogger->debug('[Excel] Start create Sheet 2: Details (w/o Location)');
    $detailsNoLocationSheet = $phpExcel->createSheet();
    $detailsNoLocationSheet->setTitle('Details (No Location)');

    // handle edge case
    $c_rawDataNoLocation = count($rawDataNoLocation);
    if ($c_rawDataNoLocation === 0) {
        $fileLogger->notice('[Details w/o Location] No data!');
        $detailsNoLocationSheet->fromArray(array('TIDAK ADA DATA'));
    } else {
        $fileLogger->debug('[Details w/o Location] Start Dump');
        $detailsNoLocationDump = array();
        $detailsNoLocationColumnHeaders = array_keys($rawDataNoLocation[0]);
        $detailsNoLocationDump[] = $detailsNoLocationColumnHeaders;

        // set values
        $c_detailsNoLocationColumnHeaders = count($detailsNoLocationColumnHeaders);
        for ($row = 0; $row < $c_rawDataNoLocation; $row++) {
            $dataRow = array();
            for ($currentCol = 0; $currentCol < $c_detailsNoLocationColumnHeaders; $currentCol++) {
                $var = $rawDataNoLocation[$row][$detailsNoLocationColumnHeaders[$currentCol]];
                if (is_bool($var)) {
                    $dataRow[] = $var ? 'TRUE' : 'FALSE';
                } else {
                    $dataRow[] = $var;
                }
            }

            $detailsNoLocationDump[] = $dataRow;
        }
        $fileLogger->debug('[Details w/o Location] Dump Done');
        $fileLogger->debug('[Details w/o Location] Writing to sheet');
        $detailsNoLocationSheet->fromArray($detailsNoLocationDump);
        unset($detailsNoLocationDump);
        unset($detailsNoLocationColumnHeaders);

        $phpExcel->addNamedRange(new PHPExcel_NamedRange('RawData', $detailsNoLocationSheet, $detailsNoLocationSheet->calculateWorksheetDimension()));
        $detailsNoLocationSheet->setAutoFilter($detailsNoLocationSheet->calculateWorksheetDimension());

        // autosize every column
        $it_detailsCell = $detailsNoLocationSheet->getRowIterator()->current()->getCellIterator();
        $it_detailsCell->setIterateOnlyExistingCells(true);
        foreach ($it_detailsCell as $cell) {
            $detailsNoLocationSheet->getColumnDimension($cell->getColumn())->setAutoSize(true);
        }

        // freeze top row
        $detailsNoLocationSheet->freezePane('A2');
    }
    // end of sheet 2: Details w/o Location.

    // generate sheet 3: rimpil
    $fileLogger->debug('[Excel] Start create Sheet 3: Rimpil');
    $rimpilSheet = $phpExcel->createSheet();
    $rimpilSheet->setTitle('Rimpil-Full Pallet Summary');

    // set the columns
    $rimpilColumns = array('location_subplant', 'location_area_code', 'location_area_name', 'category', 'count');
    $c_rimpilColumns = count($rimpilColumns);

    $rimpilDump = array();
    $rimpilHeaderRow = array();
    for ($currentCol = 0; $currentCol < $c_rimpilColumns; $currentCol++) {
        $rimpilHeaderRow[] = $rimpilColumns[$currentCol];
    }
    $rimpilDump[] = $rimpilHeaderRow;

    foreach ($palletsByAreaStatistics as $locationSubplant => $area) {
        foreach ($area as $areaNo => $areaDetails) {
            $rimpilDump[] = array($locationSubplant, $areaNo, $areaDetails['name'], 'Normal', $areaDetails['full_count']);
            $rimpilDump[] = array($locationSubplant, $areaNo, $areaDetails['name'], 'Rimpil', $areaDetails['rimpil_count']);
        }
    }

    $rimpilSheet->fromArray($rimpilDump);
    unset($rimpilDump);
    unset($rimpilHeaderRow);

    // setup filters and named range
    $rimpilSheet->setAutoFilter($rimpilSheet->calculateWorksheetDimension());
    $phpExcel->addNamedRange(new PHPExcel_NamedRange('RimpilSummary', $rimpilSheet, $rimpilSheet->calculateWorksheetDimension()));

    // autosize every column
    $it_rimpilCell = $rimpilSheet->getRowIterator()->current()->getCellIterator();
    $it_rimpilCell->setIterateOnlyExistingCells(true);
    foreach ($it_rimpilCell as $cell) {
        $rimpilSheet->getColumnDimension($cell->getColumn())->setAutoSize(true);
    }

    // freeze top row
    $rimpilSheet->freezePane('A2');
    // end of sheet 3: rimpils.

    // generate sheet 4..n: pallet aging
    $agingSheetLabels = array_merge(array('Baris'), $ageCategories, array('Total'));
    $c_agingSheetLabels = count($agingSheetLabels);

    $sheetNo = $phpExcel->getSheetCount() + 1;
    foreach ($palletsByAreaStatistics as $locationSubplant => $area) {
        foreach ($area as $areaNo => $areaDetails) {
            $agingSheetTitle = "$locationSubplant-$areaNo(" . trim($areaDetails['name']) . ')';
            // 31 is the max length of Excel sheet name
            if (strlen($agingSheetTitle) > 30) {
                $agingSheetTitle = trim(substr($agingSheetTitle, 0, 26)) . '...)';
            }
            // replace special chars with '_'
            $agingSheetTitle = preg_replace('/[\[\]\:\?*\/\\\\]/', '_', $agingSheetTitle) ?: $agingSheetTitle;

            $fileLogger->debug('[Excel] Start create Sheet ' . $sheetNo++ . ': ' . $agingSheetTitle);
            $agingSheet = $phpExcel->createSheet()
                ->setTitle($agingSheetTitle);
            $agingDump = array();

            // data will be spread horizontally, based on the line count.
            // labels will be row-based.
            for ($row = 0; $row < $c_agingSheetLabels; $row++) {
                $agingDump[] = array($agingSheetLabels[$row]);
            }

            // labels will be row-based.
            $currentCol = 'B';
            foreach ($areaDetails['age_count'] as $line => $ageCategories) {
                $agingDump[0][] = $line;

                // starting from 1, skipping 'Baris' label
                for ($row = 1; $row < $c_agingSheetLabels; $row++) {
                    $agingDump[$row][] = $ageCategories[$agingSheetLabels[$row]];
                }
                $currentCol++;
            }

            // add the current quantity for every line.
            $lineCount = $areaNo !== NO_LOCATION_AREA_CODE ? $locationInfo[$locationSubplant][$areaNo]['line_count'] : NO_LOCATION_LINE_COUNT;
            $currentQuantityDump = new SplFixedArray($lineCount + 1);
            $currentQuantityDump[0] = 'Qty.';
            for ($line = 1; $line <= $lineCount; $line++) {
                $currentQuantityDump[$line] = $areaDetails['line_quantity'][$line];
            }

            $agingDump[] = $currentQuantityDump->toArray();

            // add the total for every category
            if ($lineCount > 1) {
                $agingDump[0][] = 'Total';
                for ($row = 1; $row < $c_agingSheetLabels; $row++) {
                    $agingDump[$row][] = $areaDetails['age_count_total'][$agingSheetLabels[$row]];
                }

                // current quantity dump
                $agingDump[$c_agingSheetLabels][] = $areaDetails['line_quantity_total'];
            }

            $fileLogger->debug('[Aging] Writing to sheet ' . $agingSheetTitle);
            $agingSheet->fromArray($agingDump);

            // create chart
            // prepare X-axis labels
            $dataColEnd = PhpExcel_Cell::stringFromColumnIndex(PhpExcel_Cell::columnIndexFromString($currentCol) - 2);
            $xAxisTickValues = array(
                new PHPExcel_Chart_DataSeriesValues('String', "'$agingSheetTitle'" . "!\$B$1:\$$dataColEnd$1")
            );

            // build the data series
            // prepare series labels
            $dataSeriesValues = array();
            $dataSeriesLabels = array();
            for ($row = 1; $row < $c_agingSheetLabels - 1; $row++) {
                $labelLocation = "'$agingSheetTitle'" . '!$A$' . ($row + 1);
                $dataSeriesLabels[] = new PHPExcel_Chart_DataSeriesValues('String', $labelLocation, null, 1);
                $dataLocation = "'$agingSheetTitle'" . '!$B$' . ($row + 1) . ":\$$dataColEnd$" . ($row + 1);
                $dataSeriesValues[] = new PHPExcel_Chart_DataSeriesValues('Number', $dataLocation);
            }
            $agingSeries = new PHPExcel_Chart_DataSeries(
                PHPExcel_Chart_DataSeries::TYPE_BARCHART,
                PHPExcel_Chart_DataSeries::GROUPING_STACKED,
                range(0, count($dataSeriesValues) - 1),
                $dataSeriesLabels,
                $xAxisTickValues,
                $dataSeriesValues
            );
            $agingSeries->setPlotDirection(PHPExcel_Chart_DataSeries::DIRECTION_COL);

            // setup plot area and legend
            $plotArea = new PHPExcel_Chart_PlotArea(null, array($agingSeries));
            $legend = new PHPExcel_Chart_Legend(PHPExcel_Chart_Legend::POSITION_RIGHT, null, false);

            // setup label
            $xAxisLabel = new PHPExcel_Chart_Title('Baris');
            $yAxisLabel = new PHPExcel_Chart_Title('Jum. Palet');

            // create and set chart position
            $chart_title = new PHPExcel_Chart_Title('Aging - ' . $agingSheetTitle);
            $chart_name = "chart-$locationSubplant-$areaNo";
            $chart = new PHPExcel_Chart(
                $chart_name,
                $chart_title,
                $legend,
                $plotArea,
                true,
                0,
                $xAxisLabel,
                $yAxisLabel
            );
            // set position.
            $chart_topRow = ($c_agingSheetLabels + 3);
            $chart->setTopLeftPosition('B' . $chart_topRow);
            $colNum = PHPExcel_Cell::columnIndexFromString($currentCol);
            $chart->setBottomRightPosition(($colNum < 12 ? 'O' : $currentCol) . ($chart_topRow + 15)); // set to 15?

            $agingSheet->addChart($chart);

            // autosize columns
            $it_agingCell = $agingSheet->getRowIterator()->current()->getCellIterator();
            $it_agingCell->setIterateOnlyExistingCells(true);
            foreach ($it_agingCell as $cell) {
                $agingSheet->getColumnDimension($cell->getColumn())->setAutoSize(true);
            }

            // freeze first column
            $agingSheet->freezePane('B1');

            unset($agingDump);
        }
    }

    // write to temporary file
    $tempFileName = tempnam(sys_get_temp_dir(), 'gbj');
    if (!$tempFileName) {
        throw new RuntimeException('Cannot create temp file!');
    }

    $fileLogger->debug('[Excel] Writing output to ' . $tempFileName);
    $excelWriter = PHPExcel_IOFactory::createWriter($phpExcel, 'ReportAging');
    $excelWriter->setIncludeCharts(true)->save($tempFileName);
    $fileLogger->info('[Excel] Output written to ' . $tempFileName);
    // end of generate Excel report

    // setup mail
    $mail = Mail::getInstance('PALLET_AGING_REPORT_RECIPIENTS');

    // attach excel file.
    $mail->addAttachment($tempFileName, $excelTitle . '.xlsx');

    // write the content
    $mail->Subject = "[GBJ] Laporan Harian Palet (Rimpil & Aging) Plant $currentPlant - " . date('Y-m-d');
    $mail->Body = "Dear All,<br/><br/>

    Berikut adalah laporan harian palet rimpil dan aging untuk Plant $currentPlant.<br/>
    Laporan dibuat berdasarkan data WMS pada <strong>$dataDateTime</strong>.<br/><br/>
    
    Log Perubahan Laporan:<br/>
    <ol>
      <li>29 November 2018
        <ul>
          <li>SKU dengan size KK dianggap sebagai rimpil, terlepas dari kuantitas stok besar.</li>
          <li>Penambahan MaxPallets (jumlah palet maksimum dalam 1 baris) dan Qty. (total kuantitas stok saat ini dalam 1 baris).</li>
          <li>
            Stok tanpa lokasi disertakan dalam laporan.
          </li>
          <li>
            Sebelumnya, penghitungan rimpil hanya didasarkan pada data stok dengan lokasi.<br/>
            Sekarang, penghitungan rimpil didasarkan pada data stok global (dengan lokasi + tanpa lokasi)
          </li>
        </ul>
      </li>
    </ol><br/>
    
    Mohon tidak membalas ke alamat ini.";

    $mail->AltBody = "Dear All,\n\n
    
    Berikut adalah laporan harian palet rimpil dan aging untuk Plant $currentPlant.\n
    Laporan dibuat berdasarkan data WMS pada _${dataDateTime}_.\n\n
    
    Log Perubahan Laporan:\n
      1. 29 November 2018\n
        - SKU dengan size KK dianggap sebagai rimpil, terlepas dari kuantitas stok besar.\n
        - Penambahan MaxPallets (jumlah palet maksimum dalam 1 baris) dan Qty. (total kuantitas stok saat ini dalam 1 baris).\n
        - Stok tanpa lokasi disertakan dalam laporan.\n
        - Sebelumnya, penghitungan rimpil hanya didasarkan pada data stok dengan lokasi.\n
          Sekarang, penghitungan rimpil didasarkan pada data stok global (dengan lokasi + tanpa lokasi)\n\n
    
    Mohon tidak membalas ke alamat ini.";

    if (!$mail->send()) {
        throw new RuntimeException('Cannot send mail! Reason: ' . $mail->ErrorInfo);
    }
    $fileLogger->info('Mail successfully sent.');

    // cleanup
    unset($mail);
    unset($excelWriter);
    unset($phpExcel);
    @unlink($tempFileName);
} catch (PostgresqlDatabaseException $e) {
    $fileLogger->error('PostgresqlDatabaseException: ' . $e->getMessage(), array(
        'query' => $e->getQuery(),
        'trace' => $e->getTrace()
    ));
} catch (PHPExcel_Exception $e) {
    $fileLogger->error('PHPExcel_Exception: ' . $e->getMessage(), array(
        'trace' => $e->getTrace()
    ));
} catch (phpmailerException $e) {
    $fileLogger->error('phpmailerException: ' . $e->getMessage(), array(
        'trace' => $e->getTrace()
    ));
} catch (Exception $e) {
    $fileLogger->error('Exception: ' . $e->getMessage(), array(
        'trace' => $e->getTrace()
    ));
}
