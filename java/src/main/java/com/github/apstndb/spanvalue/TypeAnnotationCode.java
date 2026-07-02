package com.github.apstndb.spanvalue;

/** Spanner TypeAnnotationCode constants. */
public enum TypeAnnotationCode {
  TYPE_ANNOTATION_CODE_UNSPECIFIED(0),
  PG_NUMERIC(2),
  PG_JSONB(3),
  PG_OID(4);

  private final int value;

  TypeAnnotationCode(int value) {
    this.value = value;
  }

  public int getValue() {
    return value;
  }

  public static TypeAnnotationCode fromValue(int value) {
    for (TypeAnnotationCode code : values()) {
      if (code.value == value) {
        return code;
      }
    }
    return null;
  }

  /** Accept enum name or numeric annotation from protojson. */
  public static int parse(Object value) {
    if (value == null) {
      return TYPE_ANNOTATION_CODE_UNSPECIFIED.value;
    }
    if (value instanceof Number n) {
      return n.intValue();
    }
    if (value instanceof String s) {
      if (s.isEmpty()) {
        return TYPE_ANNOTATION_CODE_UNSPECIFIED.value;
      }
      if (s.chars().allMatch(Character::isDigit)
          || (s.startsWith("-") && s.substring(1).chars().allMatch(Character::isDigit))) {
        return Integer.parseInt(s);
      }
      return TypeAnnotationCode.valueOf(s).value;
    }
    if (value instanceof TypeAnnotationCode tac) {
      return tac.value;
    }
    if (value instanceof com.google.spanner.v1.TypeAnnotationCode protoAnn) {
      return protoAnn.getNumber();
    }
    if (value instanceof Enum<?> e) {
      return parse(e.name());
    }
    throw new IllegalArgumentException("cannot parse type annotation from " + value);
  }

  public static String nameFor(int annotation) {
    TypeAnnotationCode tac = fromValue(annotation);
    return tac != null ? tac.name() : null;
  }
}
