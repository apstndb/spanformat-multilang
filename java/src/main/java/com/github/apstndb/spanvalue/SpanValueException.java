package com.github.apstndb.spanvalue;

/** Base class for spanvalue formatting errors. */
public class SpanValueException extends RuntimeException {
  public SpanValueException(String message) {
    super(message);
  }
}
