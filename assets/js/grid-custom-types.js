function eXcell_ro_ts(cell) { // the eXcell name is defined here
  this.base = eXcell_ro;
  this.base(cell);
  this.setValue = function (val) {
    // actual data processing may be placed here, for now we just set value as it is
    const timestamp = (new Date(Date.parse(val))).toLocaleString(gridUtils.date.DEFAULT_LOCALE, gridUtils.date.DEFAULT_LOCALE_DATETIME_OPTIONS);
    this.setCValue(timestamp);
  }
}
// noinspection JSPotentiallyInvalidConstructorUsage
eXcell_ro_ts.prototype = new eXcell_ro;// nests all other methods from the base class

function eXcell_ro_date(cell) { // the eXcell name is defined here
  this.base = eXcell_ro;
  this.base(cell);
  this.setValue = function (val) {
    // actual data processing may be placed here, for now we just set value as it is
    const date = (new Date(Date.parse(val))).toLocaleString(gridUtils.date.DEFAULT_LOCALE, gridUtils.date.DEFAULT_LOCALE_DATE_OPTIONS);
    this.setCValue(date);
  }
}
// noinspection JSPotentiallyInvalidConstructorUsage
eXcell_ro_date.prototype = new eXcell_ro;// nests all other methods from the base class

function eXcell_ro_bool(cell) {
  this.YES_ID = 'Ya';
  this.NO_ID = 'Tidak';

  this.base = eXcell_ro;
  this.base(cell);
  this.setValue = function (val) {
    // actual data processing may be placed here, for now we just set value as it is
    // by default reverts to Indonesian
    let boolString;
    if (val instanceof String) {
      const YES_STRING = ['ya', 'yes', '1', 'true'];
      const NO_STRING = ['tidak', 'no', '0', 'false'];
      const value = val.trim().toLowerCase();

      if (YES_STRING.includes(value)) {
        boolString = this.YES_ID;
      } else if (NO_STRING.includes(value)) {
        boolString = this.NO_ID;
      } else {
        throw new Error(`Unknown boolean representation [${val}]`);
      }
    } else if (val instanceof Number) {
      boolString = val !== 0 ? this.YES_ID : this.NO_ID
    } else {
      boolString = val ? this.YES_ID : this.NO_ID
    }
    this.setCValue(boolString);
  }
}
// noinspection JSPotentiallyInvalidConstructorUsage
eXcell_ro_bool.prototype = new eXcell_ro;// nests all other methods from the base class

function eXcell_palletStatus(cell) {
  // TODO supply this from the server instead.
  const STATUS_BLOCKED = 'B';
  const STATUS_OK = 'R';
  const STATUS_KEPT = 'K';
  const STATUS_CANCELLED = 'C';
  const STATUS_NOT_VALIDATED = 'O';

  this.STATUS_MAP = Object.freeze({
    [STATUS_BLOCKED]: 'Blokir',
    [STATUS_OK]: 'OK',
    [STATUS_KEPT]: 'Keep',
    [STATUS_CANCELLED]: 'Batal',
    [STATUS_NOT_VALIDATED]: 'Dalam Validasi',
  });

  this.base = eXcell_ro;
  this.base(cell);
  this.setValue = function (val) {
    this.setCValue(`<span data-val="${val}">${this.STATUS_MAP[val]}</span>`);
  };

  this.getValue = function () {
    return this.cell.firstChild.dataset.val;
  }
}

// noinspection JSPotentiallyInvalidConstructorUsage
eXcell_palletStatus.prototype = new eXcell_ro;// nests all other methods from the base class
