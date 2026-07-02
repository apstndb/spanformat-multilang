package com.github.apstndb.spanvalue;

/** Wire payload does not match the expected encoding for the type. */
public class MalformedWireException extends SpanValueException {
  public MalformedWireException(String message) {
    super(message);
  }
}
