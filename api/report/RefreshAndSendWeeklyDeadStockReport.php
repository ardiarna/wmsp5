<?php

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

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
    $errorMessage = 'Cannot open report log file! Reason: ' . $e->getMessage();
    error_log($errorMessage);
    die ($errorMessage);
}

try {
    $db = PostgresqlDatabase::getInstance();

    // show only details and by motif.
    $fileLogger->debug('Start data refresh');
    $queryRefresh = 'REFRESH MATERIALIZED VIEW pallets_with_location_age_and_rimpil;';
    $cursorRefresh = $db->rawQuery($queryRefresh);
    $dataDateTime = date(PostgresqlDatabase::PGSQL_DATETIME_LOCAL_FORMAT);

    $ageCategories = array('Dead Stock', 'Slow');
    $queryData = "SELECT * FROM pallets_with_location_age_and_rimpil WHERE location_area_no IS NOT NULL AND pallet_age_category = ANY ($1);";
    $cursorData = $db->parameterizedQuery($queryData, array($ageCategories));

    $rawData = array();
    $qualities = array();
    $motifStatistics = array(); // group by motif
    $fileLogger->debug('Start data fetch');
    while ($row = pg_fetch_assoc($cursorData)) {
        // do transformation.
        $row['current_quantity'] = intval($row['current_quantity']);
        $row['creator_group'] = isset($row['creator_group']) ? $row['creator_group'] : 'N/A';
        $row['creator_shift'] = isset($row['creator_shift']) ? intval($row['creator_shift']) : 'N/A';
        $row['pallet_month_category'] = isset($row['pallet_month_category']) ? $row['pallet_month_category'] : 'N/A';
        $row['line'] = isset($row['line']) ? intval($row['line']) : 'N/A';
        $row['pallet_age'] = intval($row['pallet_age']);
        $row['is_rimpil'] = $row['is_rimpil'] === PostgresqlDatabase::PGSQL_TRUE;

        $rawData[] = $row;

        // collect to statistics
        /* grouping of statistics:
            * subplant
             --> summary
               --> category
                 --> quality
                   --> pallets
                   --> total motif
                   --> quantity
             --> sku
               --> motif
               --> quality
               --> size
               --> shading
         */
        $motifId = $row['motif_id'];
        $subplant = $row['production_subplant'];
        if (!isset($motifStatistics[$subplant])) {
            $motifStatistics[$subplant] = array(
                'summary' => array(),
                'sku' => array()
            );
        }

        // for first sheet
        $ageCategory = $row['pallet_age_category'];
        $quality = $row['quality'];
        if (!isset($qualities[$quality])) {
            $qualities[$quality] = true;
        }

        if (!isset($motifStatistics[$subplant]['summary'][$ageCategory])) {
            $motifStatistics[$subplant]['summary'][$ageCategory] = array();
        }

        if (!isset($motifStatistics[$subplant]['summary'][$ageCategory][$quality])) {
            $motifStatistics[$subplant]['summary'][$ageCategory][$quality] = array(
                'pallet_count' => 1,
                'total_quantity' => $row['current_quantity'],
                'motif_ids' => array(
                    $motifId => true
                )
            );
        } else {
            $motifStatistics[$subplant]['summary'][$ageCategory][$quality]['pallet_count']++;
            if (!isset($motifStatistics[$subplant]['summary'][$ageCategory][$quality]['motif_ids'][$motifId])) {
                $motifStatistics[$subplant]['summary'][$ageCategory][$quality]['motif_ids'][$motifId] = true;
            }
            $motifStatistics[$subplant]['summary'][$ageCategory][$quality]['total_quantity'] += $row['current_quantity'];
        }

        // for second sheet
        if (!isset($motifStatistics[$subplant]['sku'])) {
            $motifStatistics[$subplant]['sku'] = array();
        }

        $key = $ageCategory . $motifId . $row['size'] . $row['shading'];
        if (!isset($motifStatistics[$subplant]['sku'][$key])) {
            $motifStatistics[$subplant]['sku'][$key] = array(
                'category' => $ageCategory,
                'name' => $row['motif_name'],
                'dimension' => $row['motif_dimension'],
                'quality' => $row['quality'],
                'size' => $row['size'],
                'shading' => $row['shading'],
                'pallet_count' => 1,
                'total_quantity' => $row['current_quantity']
            );
        } else {
            $motifStatistics[$subplant]['sku'][$key]['pallet_count']++;
            $motifStatistics[$subplant]['sku'][$key]['total_quantity'] += $row['current_quantity'];
        }
    }
    $db->close();

    // handle empty data.
    if (count($rawData) === 0) {
        $fileLogger->notice('No Dead Stock or Slow pallet detected.');
        $currentDate = date('Y-m-d');

        $title = $currentDate . ' - Laporan Mingguan Dead Stock Plant ' . PlantIdHelper::getCurrentPlant();
        $body = "Selamat pagi.
                
                 Per $dataDateTime, Sistem tidak mendeteksi adanya palet yang masuk dalam kategori Dead Stock atau Slow.
                 Oleh karena itu, tidak ada laporan yang dihasilkan sistem.
                 
                 Mohon tidak membalas ke email ini.";
        throw new ReportNoDataException($title, $body);
    }

    // sort
    ksort($motifStatistics);
    $qualities = array_keys($qualities);
    usort($qualities, function ($item1, $item2) {
        if ($item1 === 'EXPORT' && $item2 === 'EKONOMI' ||
            $item1 === 'EXP' && $item2 === 'ECO' ||
            $item1 === 'EXPORT' && $item2 !== 'EKONOMI' ||
            $item1 === 'EXP' && $item2 !== 'ECO') {
            return -1;
        }

        if ($item1 === 'EKONOMI' && $item2 === 'EXPORT' ||
            $item1 === 'ECO' && $item2 === 'EXP' ||
            $item1 !== 'EKONOMI' && $item2 === 'EXPORT' ||
            $item1 !== 'ECO' && $item2 === 'EXP') {
            return 1;
        }

        return strcmp($item1, $item2);
    });
    $subplants = PlantIdHelper::getValidSubplants();
    foreach ($subplants as $subplant) {
        if (!isset($motifStatistics[$subplant])) {
            $motifStatistics[$subplant] = array(
                'summary' => array(),
                'sku' => array()
            );
        }

        // zero-out summaries based on category and quality, and do other stuff.
        foreach ($ageCategories as $category) {

            if (!isset($motifStatistics[$subplant]['summary'][$category])) {
                $motifStatistics[$subplant]['summary'][$category] = array();
            }
            foreach ($qualities as $quality) {
                if (!isset($motifStatistics[$subplant]['summary'][$category][$quality])) {
                    $motifStatistics[$subplant]['summary'][$category][$quality] = array(
                        'pallet_count' => 0,
                        'total_quantity' => 0,
                        'motif_count' => 0
                    );
                } else {
                    // transform motif_ids to motif_count.
                    $motifStatistics[$subplant]['summary'][$category][$quality]['motif_count'] =
                        count($motifStatistics[$subplant]['summary'][$category][$quality]['motif_ids']);
                    unset($motifStatistics[$subplant]['summary'][$category][$quality]['motif_ids']);
                }
            }
        }

        // sort SKUs
        usort($motifStatistics[$subplant]['sku'], function ($item1, $item2) {
            $nameCmp = strcmp($item1['name'], $item2['name']);
            $sizeCmp = strcmp($item1['size'], $item2['size']);
            $shadingCmp = strcmp($item1['shading'], $item2['shading']);
            return $nameCmp !== 0 ? $nameCmp :
                $sizeCmp !== 0 ? $sizeCmp : $shadingCmp;
        });
    }

    // generate Excel report
    /* structure of the Excel file:
      1. Sheet 1: Summary
      2. Sheet 2: Pallets grouped by motif
      3. Sheet 3: details, containing all raw data.
      */
    $fileLogger->debug('[Excel] Start create Excel');

    $currentDate = date('Y-m-d');
    $creatorName = 'GBJ P' . PlantIdHelper::getCurrentPlant() . ' System';
    $phpExcel = new PHPExcel();
    $excelTitle = $currentDate . ' - Laporan Mingguan Palet Dead Stock dan Slow Plant ' . PlantIdHelper::getCurrentPlant();
    $phpExcel->getProperties()
        ->setCreator($creatorName)
        ->setLastModifiedBy($creatorName)
        ->setTitle($excelTitle);

    $phpExcel->setActiveSheetIndex('0');

    // generate sheet 1: Summary
    $fileLogger->debug('[Excel] Start create Sheet 1: Summary');
    $summarySheet = $phpExcel->getActiveSheet()
        ->setTitle('Summary');

    // put headers first
    $summarySheet->setCellValue('A1', 'Subplant');

    // put for every category - quality - header
    // row 1: category
    // row 2: quality
    // row 3: the headers
    $summaryHeaders = array(
        'pallet_count' => 'Pallets',
        'motif_count' => 'Ttl. Motif',
        'total_quantity' => 'Qty.'
    );
    $c_summaryHeaders = count($summaryHeaders);
    $currentCol = 1;
    foreach ($ageCategories as $category) {
        $col_categoryStart = $currentCol;
        $summarySheet->setCellValueExplicitByColumnAndRow($currentCol, 1, $category, PHPExcel_Cell_DataType::TYPE_STRING);

        // put header for quality
        foreach ($qualities as $quality) {
            $col_qualityStart = $currentCol;
            $summarySheet->setCellValueExplicitByColumnAndRow($currentCol, 2, $quality, PHPExcel_Cell_DataType::TYPE_STRING);

            foreach ($summaryHeaders as $header) {
                $summarySheet->setCellValueExplicitByColumnAndRow($currentCol, 3, $header, PHPExcel_Cell_DataType::TYPE_STRING);
                $currentCol++;
            }
            $summarySheet->mergeCellsByColumnAndRow($col_qualityStart, 2, $currentCol - 1, 2);
        }

        // merge cells for the category header
        $summarySheet->mergeCellsByColumnAndRow($col_categoryStart, 1, $currentCol - 1, 1);
    }

    // put subplants header
    $summarySheet->mergeCells('A1:A3');
    $rowSubplant = 4;
    foreach ($subplants as $subplant) {
        $summarySheet->setCellValueByColumnAndRow(0, $rowSubplant, $subplant);
        $rowSubplant++;
    }
    $c_subplants = count($subplants);
    if ($c_subplants > 1) {
        $summarySheet->setCellValueByColumnAndRow(0, $rowSubplant, 'Total');
    }

    // put the values
    // values start from column number 2 (col 1, B)
    $currentCol = 1;
    $initialValueRow = 4;
    $currentRow = $initialValueRow;
    $keys_summaryHeader = array_keys($summaryHeaders);
    foreach ($subplants as $subplant) {
        foreach ($ageCategories as $category) {
            foreach ($qualities as $quality) {
                foreach ($keys_summaryHeader as $key) {
                    $value = $motifStatistics[$subplant]['summary'][$category][$quality][$key];
                    $summarySheet->setCellValueExplicitByColumnAndRow($currentCol, $currentRow, $value, PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    $currentCol++;
                }
            }
        }
        // move to next subplant
        $currentCol = 1;
        $currentRow++;
    }

    // put the total formula
    if ($c_subplants > 1) {
        $finalValueRow = $currentRow - 1;
        foreach ($ageCategories as $category) {
            foreach ($qualities as $quality) {
                foreach ($keys_summaryHeader as $key) {
                    if ($key !== 'motif_count') {
                        $column = PHPExcel_Cell::stringFromColumnIndex($currentCol);
                        $formula = "=SUM(${column}${initialValueRow}:${column}${finalValueRow})";
                        $summarySheet->setCellValueByColumnAndRow($currentCol, $currentRow, $formula);
                    }
                    $currentCol++;
                }
            }
        }
    }

    $fileLogger->debug('[Excel] Finish Sheet 1: Summary');

    // generate sheet 2: Summary by SKU.
    $fileLogger->debug('[Excel] Start create Sheet 2: Summary by SKU');

    // generate sheet 3: pallets by motif.
    $palletsBySKUSheet = $phpExcel->createSheet();
    $palletsBySKUSheet->setTitle('Summary by SKU');

    // set the columns
    $summaryColumns = array('category', 'production_subplant', 'motif_dimension', 'motif_name', 'quality', 'size', 'shading', 'pallet_count', 'total_quantity');
    $c_summaryColumns = count($summaryColumns);

    $summaryDump = array();
    $summaryDump[] = $summaryColumns;

    foreach ($subplants as $subplant) {
        foreach ($motifStatistics[$subplant]['sku'] as $skuDetails) {
            $summaryDump[] = array(
                $skuDetails['category'],
                $subplant, $skuDetails['dimension'], $skuDetails['name'], $skuDetails['quality'],
                $skuDetails['size'], $skuDetails['shading'],
                $skuDetails['pallet_count'], $skuDetails['total_quantity']
            );
        }
    }

    $fileLogger->debug('[SKU] Dump done');
    $fileLogger->debug('[SKU] Writing to sheet');
    $palletsBySKUSheet->fromArray($summaryDump);
    unset($summaryDump);
    unset($summaryColumns);

    // setup filters and named range
    $palletsBySKUSheet->setAutoFilter($palletsBySKUSheet->calculateWorksheetDimension());
    $phpExcel->addNamedRange(new PHPExcel_NamedRange('PalletsBySubplantAndMotif', $palletsBySKUSheet, $palletsBySKUSheet->calculateWorksheetDimension()));

    // autosize every column
    $it_summaryCell = $palletsBySKUSheet->getRowIterator()->current()->getCellIterator();
    $it_summaryCell->setIterateOnlyExistingCells(true);
    foreach ($it_summaryCell as $cell) {
        $palletsBySKUSheet->getColumnDimension($cell->getColumn())->setAutoSize(true);
    }

    // freeze top row
    $palletsBySKUSheet->freezePane('A2');
    // end of sheet 1: Summary
    $fileLogger->debug('[Excel] Finish create Sheet 2: Summary by SKU');

    // create sheet 2: details
    $fileLogger->debug('[Excel] Start create Sheet 3: Details');
    $detailsSheet = $phpExcel->createSheet();
    $detailsSheet->setTitle('Details');

    // set values
    $fileLogger->debug('[Details] Start dump');
    $detailsDump = array();
    $detailsColumnHeaders = array_keys($rawData[0]);
    $detailsDump[] = $detailsColumnHeaders;

    $c_rawData = count($rawData);
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
    $fileLogger->debug('[Details] Dump done');
    $fileLogger->debug('[Details] Writing to sheet');
    $detailsSheet->fromArray($detailsDump);
    unset($detailsDump);
    unset($detailsColumnHeaders);

    $phpExcel->addNamedRange(new PHPExcel_NamedRange('RawData', $detailsSheet, $detailsSheet->calculateWorksheetDimension()));
    $detailsSheet->setAutoFilter($detailsSheet->calculateWorksheetDimension());

    // autosize every column
    $it_detailsCell = $detailsSheet->getRowIterator()->current()->getCellIterator();
    $it_detailsCell->setIterateOnlyExistingCells(true);
    foreach ($it_detailsCell as $cell) {
        $detailsSheet->getColumnDimension($cell->getColumn())->setAutoSize(true);
    }

    // freeze top row
    $detailsSheet->freezePane('A2');
    $fileLogger->debug('[Excel] Finish create Sheet 3: Details');

    // write to temporary file
    $tempFileName = tempnam(sys_get_temp_dir(), 'gbj');
    if (!$tempFileName) {
        throw new RuntimeException('Cannot create temp file!');
    }

    $fileLogger->debug('[Excel] Writing output to ' . $tempFileName);
    $excelWriter = PHPExcel_IOFactory::createWriter($phpExcel, 'Excel2007');
    $excelWriter->setPreCalculateFormulas();
    $excelWriter->save($tempFileName);
    $fileLogger->debug('[Excel] Output written to ' . $tempFileName);

    // setup mail
    $mail = Mail::getInstance('REPORT_WEEKLY_PALLET_DEAD_STOCK_AND_SLOW_RECIPIENTS');

    // attach excel file.
    $mail->addAttachment($tempFileName, $excelTitle . '.xlsx');

    // write the content //
    $currentPlant = PlantIdHelper::getCurrentPlant();
    $mail->Subject = "[GBJ] Laporan Mingguan Palet Dead Stock dan Slow Plant $currentPlant - $currentDate";

    $body = "Dear All,<br/><br/>
             Berikut adalah laporan mingguan palet yang masuk dalam kategori Dead Stock dan Slow untuk Plant $currentPlant.<br/>
             Laporan dibuat berdasarkan data WMS pada <strong>$dataDateTime</strong>.<br/><br/>
             Mohon tidak membalas ke alamat ini.";
    $mail->Body = $body;

    $altBody = "Dear All,\n\n
                Berikut adalah laporan mingguan palet yang masuk dalam kategori Dead Stock dan Slow untuk Plant $currentPlant.\n
                Laporan dibuat berdasarkan data WMS pada $dataDateTime.\n\n
                Mohon tidak membalas ke alamat ini.";
    $mail->AltBody = $altBody;

    if (!$mail->send()) {
        // TODO write to logger
        throw new RuntimeException('Cannot send mail! Reason: ' . $mail->ErrorInfo);
    }
    $fileLogger->info('Weekly Dead Stock Report successfully sent via Mail.');


    // cleanup
    unset($mail);
    unset($excelWriter);
    unset($phpExcel);
    @unlink($tempFileName);
} catch (ReportNoDataException $e) { // handling no dead stock.
    try {
        $mail = Mail::getInstance('REPORT_WEEKLY_PALLET_DEAD_STOCK_AND_SLOW_RECIPIENTS');
        $mail->Subject = $e->getTitle();
        $mail->Body = $e->getMessageAsHTML();
        $mail->AltBody = $e->getMessage();

        if (!$mail->send()) {
            // TODO write to logger
            throw new RuntimeException('Cannot send mail! Reason: ' . $mail->ErrorInfo);
        }
        $fileLogger->info('Weekly Dead Stock Report successfully sent via Mail.');
    } catch (phpmailerException $ex) {
        $fileLogger->error('phpmailerException: ' . $e->getMessage(), array(
            'trace' => $e->getTrace()
        ));
    }
}
catch (PostgresqlDatabaseException $e) {
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
