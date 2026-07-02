package com.github.apstndb.spanvalue;

/** Type code is not supported by the formatter. */
public class UnknownTypeException extends SpanValueException {
  public UnknownTypeException(String message) {
    super(message);
  }
}
