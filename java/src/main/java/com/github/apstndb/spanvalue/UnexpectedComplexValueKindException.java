package com.github.apstndb.spanvalue;

/** ARRAY or STRUCT value is not encoded as a list. */
public class UnexpectedComplexValueKindException extends SpanValueException {
  public UnexpectedComplexValueKindException(String message) {
    super(message);
  }
}
