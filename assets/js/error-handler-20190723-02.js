(function (global, factory) {
  typeof exports === 'object' && typeof module !== 'undefined' ? module.exports = factory() :
    typeof define === 'function' && define.amd ? define(factory) :
      global.handleApiError = factory()
}(this, function () {

  /**
   * Handles error by showing an alert box.
   * @param {*} error
   * @param {dhtmlXWindowsCell} [parentWin]
   */
  function errorHandler(error, parentWin = null) {
    console.error(error);
    let errorMessage;

    if (error.name && error.name === 'ApiError') {
      errorMessage = getMessageFromAxiosError(error.origin);
    } else {
      errorMessage = getMessageFromAxiosError(error);
    }
    dhtmlx.alert({
      title: 'Error',
      type: 'alert-error',
      text: errorMessage,
      callback: () => {
        if (parentWin) {
          parentWin.setModal(true)
        }
      }
    })
  }

  function getMessageFromAxiosError(error) {
    let errorMessage = '';

    if (error.response) {
      // axios
      errorMessage = `Caught response ${error.response.status}.`;
      if (typeof error.response.data === 'object') {
        errorMessage += ` Details: <ul>`;
        Object.keys(error.response.data).forEach(key => {
          errorMessage += `<li>${key}: ${error.response.data[key]}`;
        });
        errorMessage += '</ul>';
      } else if (typeof error.response.data === 'string') {
        errorMessage += ` Details: `;
        errorMessage += error.response.data;
      } else {
        errorMessage += ` Details: `;
        errorMessage = error.response;
      }
    } else if (error.request) {
      // axios: no response received
      console.error(error.request);
      errorMessage += error.request;
    } else if (error instanceof Error || error.message) {
      errorMessage = error.message;
    } else {
      errorMessage = error;
    }

    return errorMessage;
  }

  return errorHandler
}));
