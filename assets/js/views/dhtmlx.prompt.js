(function (global, factory) {
  typeof exports === 'object' && typeof module !== 'undefined' ? module.exports = factory() :
    typeof define === 'function' && define.amd ? define(factory) :
      global.dhtmlx.prompt = factory()
}(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  /**
   * Shows a prompt window
   * @param {dhtmlXWindows} windows
   * @param {{ message: [string], title: [string] }} options
   * @param {dhtmlXWindowsCell} [parentWin]
   * @return {Promise<string|null>} return value of the promise.
   */
  function prompt(windows, options, parentWin = null) {
    return new Promise((resolve, reject) => {
      // setup window
      const win = windows.createWindow({
        id: 'prompt',
        center: true,
        width: 300,
        height: 200,
        park: false,
        move: false,
        resize: false,
        modal: true,
        onClose: () => {
          if (parentWin) {
            parentWin.setModal(true);
          }
        }
      });
      const title = options.title || 'Prompt';

      win.setText(title);
      win.button('park').hide();
      win.button('close').hide();
      win.button('help').hide();
      win.button('minmax').hide();
      win.setModal(true);

      const message = options.message || 'Prompt message';
      const formConfig = [
        { type: 'input', label: message, name: 'message', position: 'label-top', labelAlign: 'center' },
        { type: 'button', name: 'ok', value: 'OK' },
        { type: 'newcolumn' },
        { type: 'button', name: 'cancel', value: 'Batal' }
      ];
      const form = win.attachForm(formConfig);
      form.disableItem('ok');
      form.attachEvent('onKeyUp', (inp, ev, name) => {
        if (name === 'message') {
          const newVal = form.getItemValue(name);
          if (newVal === null || newVal.trim().length === 0) {
            form.disableItem('ok')
          } else {
            form.enableItem('ok')
          }
        }
      });
      form.setFocusOnFirstActive();
      form.attachEvent('onButtonClick', btnId => {
        if (btnId === 'ok') {
          resolve(form.getItemValue('message'));
        } else {
          resolve(null)
        }
        win.close();
      })
    });
  }

  return prompt
}));
