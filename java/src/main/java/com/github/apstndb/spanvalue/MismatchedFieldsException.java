package com.github.apstndb.spanvalue;

/** STRUCT wire value count does not match field descriptors. */
public class MismatchedFieldsException extends SpanValueException {
  public MismatchedFieldsException(String message) {
    super(message);
  }
}
