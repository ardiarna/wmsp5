// error thrown during data parsing, e.g. transforming JSON/XML.
(function (global, factory) {
  typeof exports === 'object' && typeof module !== 'undefined' ? module.exports = factory() :
    typeof define === 'function' && define.amd ? define(factory) :
      global.ParseError = factory()
}(this, function () {
  'use strict';

  ParseError.prototype = Object.create(Error.prototype, {
    constructor: {
      value: Error,
      enumerable: false,
      writable: true,
      configurable: true
    }
  });

  function ParseError(errors, message) {
    let instance = new Error(message);
    instance.errors = errors;
    if (Error.captureStackTrace) {
      Error.captureStackTrace(instance, ParseError);
    }
    return instance
  }

  if (Object.setPrototypeOf) {
    Object.setPrototypeOf(ParseError, Error);
  } else {
    ParseError.__proto__ = Error;
  }

  return ParseError
}));
