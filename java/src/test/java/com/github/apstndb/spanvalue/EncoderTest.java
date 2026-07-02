package com.github.apstndb.spanvalue;

import static org.junit.jupiter.api.Assertions.assertEquals;

import com.google.gson.Gson;
import com.google.gson.reflect.TypeToken;
import com.google.protobuf.Value;
import java.io.IOException;
import java.io.Reader;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.Base64;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.stream.Stream;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.Arguments;
import org.junit.jupiter.params.provider.MethodSource;
import org.junit.jupiter.api.Test;

class EncoderTest {
  private static Map<String, Map<String, Object>> casesByName;
  private static final Gson GSON = new Gson();
  private static final List<String> ENCODER_CASE_NAMES =
      List.of(
          "bool_true",
          "bool_false",
          "bool_null",
          "int64_positive",
          "int64_null",
          "float64_pi",
          "float64_nan",
          "float64_null",
          "string_plain",
          "bytes_ascii",
          "bytes_null",
          "array_int64",
          "array_int64_empty",
          "array_int64_with_null",
          "struct_named",
          "struct_with_null_field");

  @BeforeAll
  static void loadCases() throws IOException {
    Path path =
        Path.of("..", "testdata", "conformance.json").toAbsolutePath().normalize();
    try (Reader reader = java.nio.file.Files.newBufferedReader(path)) {
      Map<String, Object> conformance =
          GSON.fromJson(reader, new TypeToken<Map<String, Object>>() {}.getType());
      @SuppressWarnings("unchecked")
      List<Map<String, Object>> cases = (List<Map<String, Object>>) conformance.get("value_cases");
      casesByName = new LinkedHashMap<>();
      for (Map<String, Object> c : cases) {
        casesByName.put((String) c.get("name"), c);
      }
    }
  }

  private static Stream<Arguments> encoderCases() {
    return ENCODER_CASE_NAMES.stream().map(Arguments::of);
  }

  @ParameterizedTest(name = "{0}")
  @MethodSource("encoderCases")
  void encodeValueRoundTrip(String caseName) {
    Map<String, Object> caseData = casesByName.get(caseName);
    Object typ = caseData.get("type");
    Object wire = caseData.get("value");
    Object nativeValue = wireToNative(typ, wire);
    Value encoded = Gcvctor.encodeValue(typ, nativeValue);
    assertEquals(normalizeWire(wire), normalizeWire(encoded));
    String got = SpanValue.formatValue(typ, encoded, SpanValue.simpleFormatConfig());
    @SuppressWarnings("unchecked")
    String want = ((Map<String, String>) caseData.get("expected")).get("simple");
    assertEquals(want, got);
  }

  @Test
  void formatResultRow() {
    List<Object> types =
        List.of(
            Map.of("code", "INT64"),
            Map.of("code", "STRING"),
            Map.of(
                "code",
                "STRUCT",
                "structType",
                Map.of(
                    "fields",
                    List.of(
                        Map.of("name", "n", "type", Map.of("code", "INT64")),
                        Map.of("name", "s", "type", Map.of("code", "STRING"))))));
    List<Object> values = new java.util.ArrayList<>();
    values.add(42L);
    values.add("east");
    values.add(java.util.Arrays.asList(7L, null));
    List<String> got =
        SpanValue.formatResultRow(types, values, SpanValue.simpleFormatConfig());
    assertEquals(List.of("42", "east", "(7 AS n, <null> AS s)"), got);
  }

  @SuppressWarnings("unchecked")
  private static Object wireToNative(Object typ, Object wire) {
    if (wire == null || ProtoAccess.isNullValue(wire)) {
      return null;
    }
    int code = ProtoAccess.typeCode(typ);
    String kind = ProtoAccess.valueKind(wire);

    return switch (code) {
      case 1 -> kind.equals("bool") ? ProtoAccess.boolValue(wire) : wire;
      case 2, 14 -> Long.parseLong(
          kind.equals("string") ? ProtoAccess.stringValue(wire) : wire.toString());
      case 3, 4 -> {
        if ("number".equals(kind)) {
          yield ProtoAccess.numberValue(wire);
        }
        String s = ProtoAccess.stringValue(wire);
        yield switch (s) {
          case "NaN" -> Double.NaN;
          case "Infinity" -> Double.POSITIVE_INFINITY;
          case "-Infinity" -> Double.NEGATIVE_INFINITY;
          default -> throw new AssertionError("unexpected float wire: " + wire);
        };
      }
      case 5, 6, 7, 11, 12, 15, 16 -> kind.equals("string") ? ProtoAccess.stringValue(wire) : wire.toString();
      case 8, 13 -> {
        String wireStr = kind.equals("string") ? ProtoAccess.stringValue(wire) : wire.toString();
        yield wireStr.isEmpty() ? new byte[0] : Base64.getDecoder().decode(wireStr);
      }
      case 9 -> {
        Object elemType = ProtoAccess.arrayElementType(typ);
        List<Object> items = "list".equals(kind) ? ProtoAccess.listValues(wire) : (List<Object>) wire;
        List<Object> out = new ArrayList<>(items.size());
        for (Object item : items) {
          out.add(wireToNative(elemType, item));
        }
        yield out;
      }
      case 10 -> {
        List<Object> fields = ProtoAccess.structFields(typ);
        List<Object> items = "list".equals(kind) ? ProtoAccess.listValues(wire) : (List<Object>) wire;
        List<Object> out = new ArrayList<>(items.size());
        for (int i = 0; i < fields.size(); i++) {
          out.add(wireToNative(ProtoAccess.fieldType(fields.get(i)), items.get(i)));
        }
        yield out;
      }
      default -> throw new AssertionError("unsupported type code " + code);
    };
  }

  private static Object normalizeWire(Object wire) {
    if (wire == null) {
      return null;
    }
    if (wire instanceof Value value) {
      return switch (value.getKindCase()) {
        case NULL_VALUE -> null;
        case BOOL_VALUE -> Map.of("bool_value", value.getBoolValue());
        case NUMBER_VALUE -> Map.of("number_value", value.getNumberValue());
        case STRING_VALUE -> Map.of("string_value", value.getStringValue());
        case LIST_VALUE -> {
          List<Object> values = new ArrayList<>();
          for (Value item : value.getListValue().getValuesList()) {
            values.add(normalizeWire(item));
          }
          yield Map.of("list_value", Map.of("values", values));
        }
        default -> throw new AssertionError("unexpected value kind: " + value.getKindCase());
      };
    }
    String kind = ProtoAccess.valueKind(wire);
    if ("null".equals(kind) || "missing".equals(kind)) {
      return null;
    }
    if ("bool".equals(kind) || wire instanceof Boolean) {
      return Map.of("bool_value", ProtoAccess.boolValue(wire));
    }
    if ("number".equals(kind) || wire instanceof Number && !(wire instanceof Boolean)) {
      return Map.of("number_value", ProtoAccess.numberValue(wire));
    }
    if ("string".equals(kind) || wire instanceof String) {
      return Map.of("string_value", ProtoAccess.stringValue(wire));
    }
    if ("list".equals(kind) || wire instanceof List<?>) {
      List<Object> values = new ArrayList<>();
      for (Object item : ProtoAccess.listValues(wire)) {
        values.add(normalizeWire(item));
      }
      return Map.of("list_value", Map.of("values", values));
    }
    throw new AssertionError("cannot normalize wire value: " + wire);
  }
}
