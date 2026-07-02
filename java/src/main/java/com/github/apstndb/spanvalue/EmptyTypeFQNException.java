package com.github.apstndb.spanvalue;

/** PROTO or ENUM type is missing proto_type_fqn. */
public class EmptyTypeFQNException extends SpanValueException {
  public EmptyTypeFQNException(String message) {
    super(message);
  }
}
