package com.github.apstndb.spanvalue;

import java.util.Map;

/** Spanner TypeCode constants. */
public enum TypeCode {
  TYPE_CODE_UNSPECIFIED(0),
  BOOL(1),
  INT64(2),
  FLOAT64(3),
  FLOAT32(4),
  TIMESTAMP(5),
  DATE(6),
  STRING(7),
  BYTES(8),
  ARRAY(9),
  STRUCT(10),
  NUMERIC(11),
  JSON(12),
  PROTO(13),
  ENUM(14),
  INTERVAL(15),
  UUID(16);

  private final int value;

  TypeCode(int value) {
    this.value = value;
  }

  public int getValue() {
    return value;
  }

  public static TypeCode fromValue(int value) {
    for (TypeCode code : values()) {
      if (code.value == value) {
        return code;
      }
    }
    return null;
  }

  /** Accept enum name or numeric code from protojson. */
  public static int parse(Object value) {
    if (value == null) {
      return TYPE_CODE_UNSPECIFIED.value;
    }
    if (value instanceof Number n) {
      return n.intValue();
    }
    if (value instanceof String s) {
      if (s.isEmpty()) {
        return TYPE_CODE_UNSPECIFIED.value;
      }
      if (isDecimalInteger(s)) {
        return Integer.parseInt(s);
      }
      return TypeCode.valueOf(s).value;
    }
    if (value instanceof TypeCode tc) {
      return tc.value;
    }
    if (value instanceof com.google.spanner.v1.TypeCode protoCode) {
      return protoCode.getNumber();
    }
    if (value instanceof Enum<?> e) {
      return parse(e.name());
    }
    throw new IllegalArgumentException("cannot parse type code from " + value);
  }

  public static String nameFor(int code) {
    TypeCode tc = fromValue(code);
    return tc != null ? tc.name() : null;
  }

  private static boolean isDecimalInteger(String s) {
    if (s.isEmpty()) {
      return false;
    }
    int start = 0;
    if (s.charAt(0) == '-') {
      if (s.length() == 1) {
        return false;
      }
      start = 1;
    }
    for (int i = start; i < s.length(); i++) {
      if (!Character.isDigit(s.charAt(i))) {
        return false;
      }
    }
    return true;
  }
}
