(function (global, factory) {
  typeof exports === 'object' && typeof module !== 'undefined' ? module.exports = factory() :
    typeof define === 'function' && define.amd ? define(factory) :
      global.DateUtils = factory()
}(typeof self !== 'undefined' ? self : this, function () {

  /**
   * Converts Date to SQL-compatible date format.
   * @param {Date} date
   * @return {string}
   */
  function toSqlDate(date) {
    const localeDateNumberOptions = { minimumIntegerDigits: 2, useGrouping: false };
    const year = date.getFullYear().toString();
    const month = (date.getMonth() + 1).toLocaleString(undefined, localeDateNumberOptions);
    const day = date.getDate().toLocaleString(undefined, localeDateNumberOptions);
    return `${year}-${month}-${day}`;
  }

  return {
    toSqlDate
  }
}));
