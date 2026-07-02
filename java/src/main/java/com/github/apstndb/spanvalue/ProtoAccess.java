package com.github.apstndb.spanvalue;

import com.google.protobuf.ListValue;
import com.google.protobuf.NullValue;
import com.google.protobuf.Value;
import com.google.spanner.v1.StructType;
import com.google.spanner.v1.Type;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;

/** Duck-typed access to protojson maps and protobuf Type/Value objects. */
public final class ProtoAccess {
  private ProtoAccess() {}

  private static Object get(Object obj, String... names) {
    if (obj == null) {
      return null;
    }
    if (obj instanceof Map<?, ?> map) {
      for (String name : names) {
        if (map.containsKey(name)) {
          return map.get(name);
        }
      }
      return null;
    }
    if (obj instanceof Type type) {
      return getFromType(type, names);
    }
    if (obj instanceof StructType.Field field) {
      return getFromStructField(field, names);
    }
    if (obj instanceof Value value) {
      return getFromValue(value, names);
    }
    for (String name : names) {
      Object val = invokeGetter(obj, name);
      if (val != null) {
        return val;
      }
    }
    return null;
  }

  private static Object getFromType(Type type, String... names) {
    for (String name : names) {
      switch (name) {
        case "code", "Code" -> {
          return type.getCode();
        }
        case "type_annotation", "typeAnnotation", "TypeAnnotation" -> {
          return type.getTypeAnnotation();
        }
        case "proto_type_fqn", "protoTypeFqn", "ProtoTypeFqn" -> {
          return type.getProtoTypeFqn();
        }
        case "array_element_type", "arrayElementType", "ArrayElementType" -> {
          if (type.hasArrayElementType()) {
            return type.getArrayElementType();
          }
        }
        case "struct_type", "structType", "StructType" -> {
          if (type.hasStructType()) {
            return type.getStructType();
          }
        }
        default -> {}
      }
    }
    return null;
  }

  private static Object getFromStructField(StructType.Field field, String... names) {
    for (String name : names) {
      switch (name) {
        case "name", "Name" -> {
          return field.getName();
        }
        case "type", "Type" -> {
          if (field.hasType()) {
            return field.getType();
          }
        }
        default -> {}
      }
    }
    return null;
  }

  private static Object getFromValue(Value value, String... names) {
    for (String name : names) {
      switch (name) {
        case "null_value", "nullValue" -> {
          if (value.hasNullValue()) {
            return value.getNullValue();
          }
        }
        case "bool_value", "boolValue" -> {
          if (value.hasBoolValue()) {
            return value.getBoolValue();
          }
        }
        case "number_value", "numberValue" -> {
          if (value.hasNumberValue()) {
            return value.getNumberValue();
          }
        }
        case "string_value", "stringValue" -> {
          if (value.hasStringValue()) {
            return value.getStringValue();
          }
        }
        case "list_value", "listValue" -> {
          if (value.hasListValue()) {
            return value.getListValue();
          }
        }
        default -> {}
      }
    }
    return null;
  }

  private static Object invokeGetter(Object obj, String name) {
    String camel = toCamelCase(name);
    for (String candidate : new String[] {name, camel, capitalize(name), capitalize(camel)}) {
      try {
        var method = obj.getClass().getMethod("get" + capitalize(candidate));
        Object val = method.invoke(obj);
        if (val != null) {
          return val;
        }
      } catch (ReflectiveOperationException ignored) {
        // try next
      }
      try {
        var method = obj.getClass().getMethod("has" + capitalize(candidate));
        if ((boolean) method.invoke(obj)) {
          return obj.getClass().getMethod("get" + capitalize(candidate)).invoke(obj);
        }
      } catch (ReflectiveOperationException ignored) {
        // try next
      }
    }
    return null;
  }

  private static String capitalize(String s) {
    if (s == null || s.isEmpty()) {
      return s;
    }
    return Character.toUpperCase(s.charAt(0)) + s.substring(1);
  }

  private static String toCamelCase(String snake) {
    if (!snake.contains("_")) {
      return snake;
    }
    StringBuilder sb = new StringBuilder();
    boolean upper = false;
    for (char c : snake.toCharArray()) {
      if (c == '_') {
        upper = true;
      } else if (upper) {
        sb.append(Character.toUpperCase(c));
        upper = false;
      } else {
        sb.append(c);
      }
    }
    return sb.toString();
  }

  public static int typeCode(Object typ) {
    return TypeCode.parse(get(typ, "code", "Code"));
  }

  public static int typeAnnotation(Object typ) {
    return TypeAnnotationCode.parse(get(typ, "type_annotation", "typeAnnotation", "TypeAnnotation"));
  }

  public static String protoTypeFqn(Object typ) {
    Object val = get(typ, "proto_type_fqn", "protoTypeFqn", "ProtoTypeFqn");
    return val == null ? "" : val.toString();
  }

  public static Object arrayElementType(Object typ) {
    return get(typ, "array_element_type", "arrayElementType", "ArrayElementType");
  }

  public static Object structType(Object typ) {
    return get(typ, "struct_type", "structType", "StructType");
  }

  public static List<Object> structFields(Object typ) {
    Object st = structType(typ);
    if (st == null) {
      return List.of();
    }
    Object fields = get(st, "fields", "Fields");
    if (fields == null) {
      return List.of();
    }
    if (fields instanceof List<?> list) {
      List<Object> out = new ArrayList<>(list.size());
      out.addAll(list);
      return out;
    }
    if (fields instanceof StructType structType) {
      return new ArrayList<>(structType.getFieldsList());
    }
    return List.of();
  }

  public static String fieldName(Object field) {
    Object val = get(field, "name", "Name");
    return val == null ? "" : val.toString();
  }

  public static Object fieldType(Object field) {
    return get(field, "type", "Type");
  }

  /** Return wire kind: null, bool, number, string, list, or missing. */
  public static String valueKind(Object value) {
    if (value == null) {
      return "null";
    }
    if (value instanceof Map<?, ?> map) {
      if (map.containsKey("null_value") || map.containsKey("nullValue")) {
        return "null";
      }
      if (map.containsKey("bool_value") || map.containsKey("boolValue")) {
        return "bool";
      }
      if (map.containsKey("number_value") || map.containsKey("numberValue")) {
        return "number";
      }
      if (map.containsKey("string_value") || map.containsKey("stringValue")) {
        return "string";
      }
      if (map.containsKey("list_value") || map.containsKey("listValue")) {
        return "list";
      }
      return "missing";
    }
    if (value instanceof Boolean) {
      return "bool";
    }
    if (value instanceof Number && !(value instanceof Boolean)) {
      return "number";
    }
    if (value instanceof String) {
      return "string";
    }
    if (value instanceof List<?>) {
      return "list";
    }
    if (value instanceof Value protoValue) {
      return valueKindFromProtoValue(protoValue);
    }
    if (value instanceof ListValue listValue) {
      return "list";
    }
    Object nullVal = get(value, "null_value", "nullValue");
    if (nullVal != null || hasNullValue(value)) {
      return "null";
    }
    if (get(value, "bool_value", "boolValue") != null) {
      return "bool";
    }
    if (get(value, "number_value", "numberValue") != null) {
      return "number";
    }
    if (get(value, "string_value", "stringValue") != null) {
      return "string";
    }
    if (get(value, "list_value", "listValue") != null) {
      return "list";
    }
    return "missing";
  }

  private static boolean hasNullValue(Object value) {
    try {
      var method = value.getClass().getMethod("hasNullValue");
      return (boolean) method.invoke(value);
    } catch (ReflectiveOperationException e) {
      return false;
    }
  }

  private static String valueKindFromProtoValue(Value value) {
    switch (value.getKindCase()) {
      case NULL_VALUE:
        return "null";
      case BOOL_VALUE:
        return "bool";
      case NUMBER_VALUE:
        return "number";
      case STRING_VALUE:
        return "string";
      case LIST_VALUE:
        return "list";
      default:
        return "missing";
    }
  }

  public static boolean isNullValue(Object value) {
    String kind = valueKind(value);
    return "null".equals(kind) || "missing".equals(kind);
  }

  public static boolean boolValue(Object value) {
    if (value instanceof Boolean b) {
      return b;
    }
    if (value instanceof Map<?, ?> map) {
      Object v = map.get("bool_value");
      if (v == null) {
        v = map.get("boolValue");
      }
      return Boolean.TRUE.equals(v);
    }
    Object val = get(value, "bool_value", "boolValue");
    return Boolean.TRUE.equals(val);
  }

  public static double numberValue(Object value) {
    if (value instanceof Number n && !(value instanceof Boolean)) {
      return n.doubleValue();
    }
    if (value instanceof Map<?, ?> map) {
      Object v = map.get("number_value");
      if (v == null) {
        v = map.get("numberValue");
      }
      return ((Number) v).doubleValue();
    }
    Object val = get(value, "number_value", "numberValue");
    return ((Number) val).doubleValue();
  }

  public static String stringValue(Object value) {
    if (value instanceof String s) {
      return s;
    }
    if (value instanceof Map<?, ?> map) {
      Object v = map.get("string_value");
      if (v == null) {
        v = map.get("stringValue");
      }
      return v == null ? "" : v.toString();
    }
    Object val = get(value, "string_value", "stringValue");
    return val == null ? "" : val.toString();
  }

  @SuppressWarnings("unchecked")
  public static List<Object> listValues(Object value) {
    if (value instanceof List<?> list) {
      return new ArrayList<>(list);
    }
    if (value instanceof Map<?, ?> map) {
      Object lv = map.get("list_value");
      if (lv == null) {
        lv = map.get("listValue");
      }
      if (lv instanceof Map<?, ?> lvMap) {
        Object vals = lvMap.get("values");
        if (vals == null) {
          vals = lvMap.get("Values");
        }
        if (vals instanceof List<?> values) {
          return new ArrayList<>(values);
        }
      }
      if (lv instanceof ListValue listValue) {
        return new ArrayList<>(listValue.getValuesList());
      }
    }
    if (value instanceof ListValue listValue) {
      return new ArrayList<>(listValue.getValuesList());
    }
    if (value instanceof Value protoValue && protoValue.hasListValue()) {
      return new ArrayList<>(protoValue.getListValue().getValuesList());
    }
    Object lv = get(value, "list_value", "listValue");
    if (lv != null) {
      Object vals = get(lv, "values", "Values");
      if (vals instanceof List<?> values) {
        return new ArrayList<>(values);
      }
      if (vals instanceof Iterable<?> iterable) {
        List<Object> out = new ArrayList<>();
        for (Object item : iterable) {
          out.add(item);
        }
        return out;
      }
    }
    return List.of();
  }
}
