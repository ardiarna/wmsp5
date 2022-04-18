(function (global, factory) {
  typeof exports === 'object' && typeof module !== 'undefined' ? module.exports = factory() :
    typeof define === 'function' && define.amd ? define(factory) :
      global.Timer = factory()
}(this, function () {
  'use strict';

  Timer.prototype = Object.create({}, {
    constructor: {
      enumerable: false,
      writable: true,
      configurable: true
    }
  });

  function Timer(fn, t) {
    let timerObj = setInterval(fn, t);

    /**
     * Stops the timer.
     * @return {Timer}
     */
    this.stop = function () {
      if (timerObj) {
        clearInterval(timerObj);
        timerObj = null;
      }
      return this;
    };

    /**
     * Starts the timer, if it is not currently running.
     * @return {Timer}
     */
    this.start = function () {
      if (!timerObj) {
        this.stop();
        timerObj = setInterval(fn, t);
      }
      return this;
    };

    /**
     * Restarts the timer with a new interval.
     * @return {Timer}
     */
    this.reset = function (newT) {
      t = newT;
      return this.stop().start();
    }
  }

  return Timer
}));
