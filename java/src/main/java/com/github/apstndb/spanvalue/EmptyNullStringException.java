package com.github.apstndb.spanvalue;

/** FormatConfig null_string must not be empty. */
public class EmptyNullStringException extends SpanValueException {
  public EmptyNullStringException(String message) {
    super(message);
  }
}
