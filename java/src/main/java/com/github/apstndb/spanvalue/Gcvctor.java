package com.github.apstndb.spanvalue;

import static com.github.apstndb.spanvalue.ProtoAccess.arrayElementType;
import static com.github.apstndb.spanvalue.ProtoAccess.fieldName;
import static com.github.apstndb.spanvalue.ProtoAccess.fieldType;
import static com.github.apstndb.spanvalue.ProtoAccess.structFields;
import static com.github.apstndb.spanvalue.ProtoAccess.typeAnnotation;
import static com.github.apstndb.spanvalue.ProtoAccess.typeCode;

import com.google.protobuf.ListValue;
import com.google.protobuf.NullValue;
import com.google.protobuf.Value;
import java.util.Base64;
import java.util.List;
import java.util.Map;

/** Encode native values to wire {@link com.google.protobuf.Value} messages. */
public final class Gcvctor {
  private Gcvctor() {}

  public static Value encodeValue(Object typ, Object nativeValue) {
    int code = typeCode(typ);

    if (nativeValue == null) {
      return Value.newBuilder().setNullValue(NullValue.NULL_VALUE).build();
    }

    return switch (code) {
      case 1 -> Value.newBuilder().setBoolValue((Boolean) toBoolean(nativeValue)).build();
      case 2 -> Value.newBuilder().setStringValue(toInt64String(nativeValue)).build();
      case 14 -> Value.newBuilder().setStringValue(toInt64String(nativeValue)).build();
      case 3 -> encodeFloat64(nativeValue);
      case 4 -> encodeFloat32(nativeValue);
      case 5, 6, 7, 11, 15, 16 -> Value.newBuilder().setStringValue(nativeValue.toString()).build();
      case 12 -> Value.newBuilder().setStringValue(toJsonString(nativeValue)).build();
      case 8, 13 -> encodeBytes(nativeValue);
      case 9 -> encodeArray(typ, nativeValue);
      case 10 -> encodeStruct(typ, nativeValue);
      default -> {
        int ann = typeAnnotation(typ);
        if (code == 11 && ann == TypeAnnotationCode.PG_NUMERIC.getValue()) {
          yield Value.newBuilder().setStringValue(nativeValue.toString()).build();
        }
        if (ann == TypeAnnotationCode.PG_JSONB.getValue()) {
          yield Value.newBuilder().setStringValue(toJsonString(nativeValue)).build();
        }
        if (ann == TypeAnnotationCode.PG_OID.getValue()) {
          yield Value.newBuilder().setStringValue(toInt64String(nativeValue)).build();
        }
        throw new SpanValueException("unsupported type code for encoding: " + code);
      }
    };
  }

  private static boolean toBoolean(Object nativeValue) {
    if (nativeValue instanceof Boolean b) {
      return b;
    }
    throw new IllegalArgumentException("BOOL native value must be Boolean");
  }

  private static String toInt64String(Object nativeValue) {
    if (nativeValue instanceof Long l) {
      return Long.toString(l);
    }
    if (nativeValue instanceof Integer i) {
      return Integer.toString(i);
    }
    if (nativeValue instanceof String s) {
      return s;
    }
    throw new IllegalArgumentException("INT64 native value must be Long, Integer, or String");
  }

  private static Value encodeFloat64(Object nativeValue) {
    double v = toDouble(nativeValue);
    if (Double.isNaN(v)) {
      return Value.newBuilder().setStringValue("NaN").build();
    }
    if (Double.isInfinite(v)) {
      return Value.newBuilder().setStringValue(v > 0 ? "Infinity" : "-Infinity").build();
    }
    return Value.newBuilder().setNumberValue(v).build();
  }

  private static Value encodeFloat32(Object nativeValue) {
    double v = FloatFmt.narrowFloat32(toDouble(nativeValue));
    if (Double.isNaN(v)) {
      return Value.newBuilder().setStringValue("NaN").build();
    }
    if (Double.isInfinite(v)) {
      return Value.newBuilder().setStringValue(v > 0 ? "Infinity" : "-Infinity").build();
    }
    return Value.newBuilder().setNumberValue(v).build();
  }

  private static double toDouble(Object nativeValue) {
    if (nativeValue instanceof Double d) {
      return d;
    }
    if (nativeValue instanceof Float f) {
      return f;
    }
    if (nativeValue instanceof Integer i) {
      return i;
    }
    if (nativeValue instanceof Long l) {
      return l;
    }
    throw new IllegalArgumentException("FLOAT native value must be Double or Float");
  }

  private static Value encodeBytes(Object nativeValue) {
    if (!(nativeValue instanceof byte[] bytes)) {
      throw new IllegalArgumentException("BYTES/PROTO native value must be byte[]");
    }
    return Value.newBuilder()
        .setStringValue(Base64.getEncoder().encodeToString(bytes))
        .build();
  }

  private static String toJsonString(Object nativeValue) {
    if (nativeValue instanceof String s) {
      return s;
    }
    return JsonWire.encode(nativeValue);
  }

  private static Value encodeArray(Object typ, Object nativeValue) {
    if (!(nativeValue instanceof List<?> list)) {
      throw new IllegalArgumentException("ARRAY native value must be List");
    }
    Object elemType = arrayElementType(typ);
    ListValue.Builder builder = ListValue.newBuilder();
    for (Object item : list) {
      builder.addValues(encodeValue(elemType, item));
    }
    return Value.newBuilder().setListValue(builder).build();
  }

  @SuppressWarnings("unchecked")
  private static Value encodeStruct(Object typ, Object nativeValue) {
    List<Object> fields = structFields(typ);
    ListValue.Builder builder = ListValue.newBuilder();
    if (nativeValue instanceof Map<?, ?> map) {
      for (Object fieldObj : fields) {
        String name = fieldName(fieldObj);
        if (!map.containsKey(name)) {
          throw new MismatchedFieldsException("STRUCT map missing field " + name);
        }
        builder.addValues(encodeValue(fieldType(fieldObj), map.get(name)));
      }
      return Value.newBuilder().setListValue(builder).build();
    }
    if (nativeValue instanceof List<?> list) {
      if (list.size() != fields.size()) {
        throw new MismatchedFieldsException(
            "STRUCT field count mismatch: got " + list.size() + ", want " + fields.size());
      }
      for (int i = 0; i < fields.size(); i++) {
        builder.addValues(encodeValue(fieldType(fields.get(i)), list.get(i)));
      }
      return Value.newBuilder().setListValue(builder).build();
    }
    throw new IllegalArgumentException("STRUCT native value must be List or Map");
  }

  /** Minimal JSON encoder for native Map/List values (no runtime JSON dependency). */
  static final class JsonWire {
    private JsonWire() {}

    static String encode(Object value) {
      StringBuilder sb = new StringBuilder();
      write(sb, value);
      return sb.toString();
    }

    @SuppressWarnings("unchecked")
    private static void write(StringBuilder sb, Object value) {
      if (value == null) {
        sb.append("null");
      } else if (value instanceof String s) {
        sb.append('"');
        for (int i = 0; i < s.length(); i++) {
          char c = s.charAt(i);
          switch (c) {
            case '"' -> sb.append("\\\"");
            case '\\' -> sb.append("\\\\");
            case '\b' -> sb.append("\\b");
            case '\f' -> sb.append("\\f");
            case '\n' -> sb.append("\\n");
            case '\r' -> sb.append("\\r");
            case '\t' -> sb.append("\\t");
            default -> {
              if (c < 0x20) {
                sb.append(String.format("\\u%04x", (int) c));
              } else {
                sb.append(c);
              }
            }
          }
        }
        sb.append('"');
      } else if (value instanceof Boolean b) {
        sb.append(b ? "true" : "false");
      } else if (value instanceof Number n) {
        sb.append(n);
      } else if (value instanceof List<?> list) {
        sb.append('[');
        for (int i = 0; i < list.size(); i++) {
          if (i > 0) {
            sb.append(',');
          }
          write(sb, list.get(i));
        }
        sb.append(']');
      } else if (value instanceof Map<?, ?> map) {
        sb.append('{');
        boolean first = true;
        for (Map.Entry<?, ?> entry : map.entrySet()) {
          if (!first) {
            sb.append(',');
          }
          first = false;
          write(sb, String.valueOf(entry.getKey()));
          sb.append(':');
          write(sb, entry.getValue());
        }
        sb.append('}');
      } else {
        throw new IllegalArgumentException(
            "JSON native value must be String, Map, List, or scalar");
      }
    }
  }
}
