;(function (global, factory) {
  if (typeof define === 'function' && define.amd) {
    define(['pdfMake'], factory);
  } else if (typeof module === 'object' && module.exports) {
    module.exports = factory(require('pdfMake'))
  } else {
    if (!global.hasOwnProperty('pdfMake')) {
      throw Error('pdfMake is not loaded!')
    }
    global.gridUtils = factory(global.pdfMake)
  }
}(this, function (pdfMake) {

  const DEFAULT_LOCALE = 'id-ID';
  const DEFAULT_LOCALE_DATETIME_OPTIONS = {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    timeZoneName: 'short'
  };
  const DEFAULT_LOCALE_DATE_OPTIONS = {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  };
  const LOCALE_ISO_DATE_OPTIONS = {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  };

  /**
   * Generates PDF from dhtmlXGrid object, with predefined settings.
   * Only non-hidden columns, non-checkbox columns, and filtered rows are shown.
   * @param {dhtmlXGridObject} grid dhtmlXGrid object
   * @param {string} title title of the document
   * @param {string} userId user that generates the object.
   * @param {string} colWidths widths of visible cells.
   * @returns {*|Document|Object} generated PDF
   */
  function generateFilteredPdf(grid, title, userId, colWidths = '') {
    const timestamp = new Date();
    const generatedAt = `Dibuat oleh [${userId}] pada ${timestamp.toLocaleString(DEFAULT_LOCALE, DEFAULT_LOCALE_DATETIME_OPTIONS)}`;

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

  function clearAllGridFilters(grid) {
    grid.filters.forEach(filter => {
      const filterElement = filter[0];
      filterElement.value = '';
    });
    grid.filterBy(0, '');
  }

  const COLUMN_DELIMITER = ',';
  const LINE_DELIMITER = '\n';

  /**
   * Open a new window to show the PDF.
   * @param {string} title
   * @param {string} filename
   * @param {Blob} blob
   * @param {dhtmlXWindowsCell} [prevWindow]
   * @param {dhtmlXWindows} [windows]
   */
  function openPDFWindow(title, filename, blob, prevWindow = null, windows = null) {
    dhx.ajax.cache = true; // fix blob not showing.
    if (!windows) {
      windows = new dhtmlXWindows()
    }
    const childWin = windows.createWindow('w2', 0, 0, 800, 450);
    childWin.centerOnScreen();
    childWin.setText('[PDF] ' + title);
    childWin.button('park').hide();
    childWin.setModal(true);

    const fileName = filename.toLowerCase().endsWith('.pdf') ? filename : filename + '.pdf';
    const file = new File([blob], fileName, {type: 'application/pdf', lastModified: Date.now()});
    let fileURL = URL.createObjectURL(file);

    childWin.attachURL(fileURL);
    childWin.attachEvent('onClose', () => {
      dhx.ajax.cache = false;
      if (prevWindow && prevWindow instanceof dhtmlXWindowsCell) {
        prevWindow.setModal(true)
      }
      if (fileURL) {
        URL.revokeObjectURL(fileURL);
        blob = null;
        fileURL = null;
      }
      return true
    });
    return childWin
  }

  /**
   * Generates CSV from dhtmlXGrid object, and triggers download.
   * Only non-hidden columns and filtered rows are shown.
   * @param grid dhtmlXGrid object
   * @param {null|string} filename filename of the document.
   */
  function downloadFilteredCSV(grid, filename = null) {
    if (filename === null) {
      filename = (new Date).toLocaleDateString(DEFAULT_LOCALE, DEFAULT_LOCALE_DATE_OPTIONS);
    }

    const tableHeaders = [];
    for (let i = 0; i < grid.getColumnsNum(); i++) {
      if (!grid.isColumnHidden(i)) {
        tableHeaders.push(`"${grid.getColumnLabel(i, 0)}"`);
      }
    }

    const tableRows = [];
    const rowCount = grid.getRowsNum();
    for (let i = 0; i < rowCount; i++) {
      const rowId = grid.getRowId(i);
      const tableRow = [];
      grid.forEachCell(rowId, (cell, index) => {
        if (!grid.isColumnHidden(index)) {
          const colType = grid.getColType(index);
          if (colType === 'ron') {
            tableRow.push(grid.getColumnId(index) === 'no' ? i + 1 : cell.getValue())
          } else {
            tableRow.push(`"${cell.getValue()}"`)
          }
        }
      });
      tableRows.push(tableRow);
    }

    downloadCSV(filename, tableRows, tableHeaders);
  }

  /**
   * Download CSV from existing array dumo.
   * @param {string} filename
   * @param {Array} content
   * @param {Array} [headers]
   */
  function downloadCSV(filename, content, headers = []) {
    let result = headers.length > 1 ? headers.join(COLUMN_DELIMITER) + LINE_DELIMITER : '';
    content.forEach(row => {
      result += row.join(COLUMN_DELIMITER) + LINE_DELIMITER
    });

    const blob = new Blob([result], {type: 'data:text/csv;charset=utf-8;'});

    const expFilename = filename.slice(-4).toLowerCase() !== '.csv' ? filename + '.csv' : filename;
    if (navigator.msSaveBlob) {
      navigator.msSaveBlob(blob, expFilename)
    }
    const link = document.createElement('a');
    if (link.download !== undefined) {
      link.setAttribute('href', URL.createObjectURL(blob));
      link.setAttribute('download', expFilename);
      link.style.visibility = 'hidden';
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    }
  }

  function getCurrentLocaleDate(locale = DEFAULT_LOCALE, options = DEFAULT_LOCALE_DATE_OPTIONS) {
    return (new Date()).toLocaleDateString(locale, options)
  }

  function getCurrentLocaleDateTime(locale = DEFAULT_LOCALE, options = DEFAULT_LOCALE_DATETIME_OPTIONS) {
    return (new Date()).toLocaleString(locale, options);
  }

  const TEXT_LEFT_ALIGN = 'text-align:left;';
  const TEXT_CENTER_ALIGN = 'text-align:center;';
  const TEXT_RIGHT_ALIGN = 'text-align:right;padding-right:10px;';
  const TEXT_BOLD = 'font-weight: bold;';

  const HEADER_TEXT_FILTER = '#text_filter';
  const HEADER_NUMERIC_FILTER = '#numeric_filter';
  const HEADER_SELECT_FILTER = '#select_filter_strict';
  const HEADER_CHECKBOX_MASTER = '#master_checkbox';

  const STATISTICS_COUNT = '#stat_count';
  const STATISTICS_TOTAL = '#stat_total';

  return {
    date: {
      DEFAULT_LOCALE, DEFAULT_LOCALE_DATE_OPTIONS, DEFAULT_LOCALE_DATETIME_OPTIONS, LOCALE_ISO_DATE_OPTIONS,
      getCurrentLocaleDate, getCurrentLocaleDateTime
    },
    spans: {COLUMN: '#cspan', ROW: '#rspan'},
    reducers: {STATISTICS_TOTAL, STATISTICS_COUNT},
    styles: {TEXT_BOLD, TEXT_LEFT_ALIGN, TEXT_CENTER_ALIGN, TEXT_RIGHT_ALIGN},
    headerFilters: {TEXT: HEADER_TEXT_FILTER, SELECT: HEADER_SELECT_FILTER, NUMERIC: HEADER_NUMERIC_FILTER, CHECKBOX: HEADER_CHECKBOX_MASTER},
    generateFilteredPdf, downloadFilteredCSV, downloadCSV,
    openPDFWindow,

    clearAllGridFilters
  }
}));
