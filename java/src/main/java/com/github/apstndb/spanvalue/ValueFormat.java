package com.github.apstndb.spanvalue;

import static com.github.apstndb.spanvalue.ProtoAccess.arrayElementType;
import static com.github.apstndb.spanvalue.ProtoAccess.boolValue;
import static com.github.apstndb.spanvalue.ProtoAccess.fieldName;
import static com.github.apstndb.spanvalue.ProtoAccess.fieldType;
import static com.github.apstndb.spanvalue.ProtoAccess.isNullValue;
import static com.github.apstndb.spanvalue.ProtoAccess.listValues;
import static com.github.apstndb.spanvalue.ProtoAccess.numberValue;
import static com.github.apstndb.spanvalue.ProtoAccess.protoTypeFqn;
import static com.github.apstndb.spanvalue.ProtoAccess.stringValue;
import static com.github.apstndb.spanvalue.ProtoAccess.structFields;
import static com.github.apstndb.spanvalue.ProtoAccess.typeCode;
import static com.github.apstndb.spanvalue.ProtoAccess.valueKind;
import static com.github.apstndb.spanvalue.TypeFormat.formatTypeCode;
import static com.github.apstndb.spanvalue.TypeFormat.formatTypeVerbose;

import java.math.BigInteger;
import java.util.ArrayList;
import java.util.List;

/** FormatConfig presets and value formatting. */
public final class ValueFormat {
  private ValueFormat() {}

  public enum Preset {
    SIMPLE,
    LITERAL,
    SPANNER_CLI
  }

  public record FormatConfig(
      Preset preset, String nullString, Quote.LiteralQuoteConfig quote) {

    public FormatConfig {
      if (nullString == null || nullString.isEmpty()) {
        throw new EmptyNullStringException("null_string must not be empty");
      }
      if (quote == null) {
        quote = new Quote.LiteralQuoteConfig();
      }
    }

    public FormatConfig withNullString(String newNullString) {
      return new FormatConfig(preset, newNullString, quote);
    }
  }

  public static FormatConfig simpleFormatConfig() {
    return simpleFormatConfig("<null>");
  }

  public static FormatConfig simpleFormatConfig(String nullString) {
    return new FormatConfig(Preset.SIMPLE, nullString, new Quote.LiteralQuoteConfig());
  }

  public static FormatConfig literalFormatConfig() {
    return literalFormatConfig(new Quote.LiteralQuoteConfig(), "NULL");
  }

  public static FormatConfig literalFormatConfig(Quote.LiteralQuoteConfig quote) {
    return literalFormatConfig(quote, "NULL");
  }

  public static FormatConfig literalFormatConfig(Quote.LiteralQuoteConfig quote, String nullString) {
    return new FormatConfig(
        Preset.LITERAL, nullString, Quote.LiteralQuoteConfig.normalize(quote));
  }

  public static FormatConfig spannerCliFormatConfig() {
    return spannerCliFormatConfig("NULL");
  }

  public static FormatConfig spannerCliFormatConfig(String nullString) {
    return new FormatConfig(Preset.SPANNER_CLI, nullString, new Quote.LiteralQuoteConfig());
  }

  private static boolean isComplexType(int code) {
    return code == TypeCode.ARRAY.getValue() || code == TypeCode.STRUCT.getValue();
  }

  private static boolean isScalarType(int code) {
    return code == TypeCode.BOOL.getValue()
        || code == TypeCode.INT64.getValue()
        || code == TypeCode.ENUM.getValue()
        || code == TypeCode.FLOAT32.getValue()
        || code == TypeCode.FLOAT64.getValue()
        || code == TypeCode.STRING.getValue()
        || code == TypeCode.BYTES.getValue()
        || code == TypeCode.PROTO.getValue()
        || code == TypeCode.TIMESTAMP.getValue()
        || code == TypeCode.DATE.getValue()
        || code == TypeCode.NUMERIC.getValue()
        || code == TypeCode.JSON.getValue()
        || code == TypeCode.INTERVAL.getValue()
        || code == TypeCode.UUID.getValue();
  }

  private static void requireStringWire(Object value, int code) {
    if (!"string".equals(valueKind(value))) {
      throw new MalformedWireException(
          formatTypeCodeName(code) + " value kind " + quote(valueKind(value)));
    }
  }

  private static void requireBoolWire(Object value, int code) {
    if (!"bool".equals(valueKind(value))) {
      throw new MalformedWireException(
          formatTypeCodeName(code) + " value kind " + quote(valueKind(value)));
    }
  }

  private static void validateFloatWire(Object value, int code) {
    String kind = valueKind(value);
    if ("number".equals(kind)) {
      return;
    }
    if ("string".equals(kind)) {
      String s = stringValue(value);
      if ("NaN".equals(s) || "Infinity".equals(s) || "-Infinity".equals(s)) {
        return;
      }
      throw new MalformedWireException(
          formatTypeCodeName(code) + " unexpected float string " + quote(s));
    }
    throw new MalformedWireException(
        formatTypeCodeName(code) + " value kind " + quote(kind));
  }

  private static String quote(String s) {
    return "'" + s + "'";
  }

  private static String formatTypeCodeName(int code) {
    return formatTypeCode(code, TypeFormat.UnknownMode.VERBOSE);
  }

  private static double gcvFloat64(Object value) {
    String kind = valueKind(value);
    if ("number".equals(kind)) {
      return numberValue(value);
    }
    if ("string".equals(kind)) {
      String s = stringValue(value);
      return switch (s) {
        case "NaN" -> Double.NaN;
        case "Infinity" -> Double.POSITIVE_INFINITY;
        case "-Infinity" -> Double.NEGATIVE_INFINITY;
        default ->
            throw new MalformedWireException("FLOAT64 unexpected float string " + quote(s));
      };
    }
    throw new MalformedWireException("FLOAT64 value kind " + quote(kind));
  }

  private static double gcvFloat32(Object value) {
    return FloatFmt.narrowFloat32(gcvFloat64(value));
  }

  private static void validateScalarWire(Object typ, Object value) {
    if (typ == null) {
      throw new MalformedWireException("nil type with value kind " + quote(valueKind(value)));
    }
    if (isNullValue(value)) {
      throw new MalformedWireException(
          formatTypeCodeName(typeCode(typ)) + " unexpected null value");
    }
    int code = typeCode(typ);
    if (code == TypeCode.BOOL.getValue()) {
      requireBoolWire(value, code);
    } else if (code == TypeCode.INT64.getValue()
        || code == TypeCode.ENUM.getValue()
        || code == TypeCode.STRING.getValue()
        || code == TypeCode.BYTES.getValue()
        || code == TypeCode.PROTO.getValue()
        || code == TypeCode.TIMESTAMP.getValue()
        || code == TypeCode.DATE.getValue()
        || code == TypeCode.NUMERIC.getValue()
        || code == TypeCode.INTERVAL.getValue()
        || code == TypeCode.UUID.getValue()
        || code == TypeCode.JSON.getValue()) {
      requireStringWire(value, code);
    } else if (code == TypeCode.FLOAT32.getValue() || code == TypeCode.FLOAT64.getValue()) {
      validateFloatWire(value, code);
    } else if (code == TypeCode.TYPE_CODE_UNSPECIFIED.getValue()) {
      throw new UnknownTypeException(String.valueOf(typ));
    } else if (!isScalarType(code)) {
      throw new UnknownTypeException(String.valueOf(typ));
    }
  }

  private static String trimSpannerCliNumericFraction(String s) {
    if (!s.contains(".")) {
      return s;
    }
    s = s.replaceAll("0+$", "");
    return s.replaceAll("\\.$", "");
  }

  private static String numericWireString(Object value) {
    return stringValue(value);
  }

  private static String stringBasedLiteral(
      String typeName, String payload, Quote.LiteralQuoteConfig quote) {
    return typeName + " " + Quote.toStringLiteral(payload, quote);
  }

  private static String formatScalarSimple(Object typ, Object value) {
    validateScalarWire(typ, value);
    int code = typeCode(typ);
    if (code == TypeCode.BOOL.getValue()) {
      return boolValue(value) ? "true" : "false";
    }
    if (code == TypeCode.INT64.getValue()
        || code == TypeCode.ENUM.getValue()
        || code == TypeCode.STRING.getValue()
        || code == TypeCode.TIMESTAMP.getValue()
        || code == TypeCode.DATE.getValue()
        || code == TypeCode.JSON.getValue()
        || code == TypeCode.INTERVAL.getValue()
        || code == TypeCode.UUID.getValue()) {
      return stringValue(value);
    }
    if (code == TypeCode.FLOAT32.getValue()) {
      return FloatFmt.formatGoG(gcvFloat32(value), 32);
    }
    if (code == TypeCode.FLOAT64.getValue()) {
      return FloatFmt.formatGoG(gcvFloat64(value), 64);
    }
    if (code == TypeCode.BYTES.getValue() || code == TypeCode.PROTO.getValue()) {
      return BytesFmt.readableStringFromBase64Wire(stringValue(value));
    }
    if (code == TypeCode.NUMERIC.getValue()) {
      return numericWireString(value);
    }
    throw new UnknownTypeException(String.valueOf(typ));
  }

  private static void validateInt64Wire(String s) {
    try {
      BigInteger bi = new BigInteger(s, 10);
      BigInteger min = BigInteger.valueOf(Long.MIN_VALUE);
      BigInteger max = BigInteger.valueOf(Long.MAX_VALUE);
      if (bi.compareTo(min) < 0 || bi.compareTo(max) > 0) {
        throw new MalformedWireException("INT64 out of range " + quote(s));
      }
    } catch (NumberFormatException e) {
      throw new MalformedWireException("invalid INT64 wire " + quote(s));
    }
  }

  private static String formatScalarLiteral(
      Object typ, Object value, Quote.LiteralQuoteConfig quote) {
    validateScalarWire(typ, value);
    int code = typeCode(typ);
    if (code == TypeCode.BOOL.getValue()) {
      return boolValue(value) ? "true" : "false";
    }
    if (code == TypeCode.INT64.getValue()) {
      String s = stringValue(value);
      validateInt64Wire(s);
      return s;
    }
    if (code == TypeCode.FLOAT32.getValue()) {
      return FloatFmt.float32ToLiteral(gcvFloat32(value), quote);
    }
    if (code == TypeCode.FLOAT64.getValue()) {
      return FloatFmt.float64ToLiteral(gcvFloat64(value), quote);
    }
    if (code == TypeCode.STRING.getValue()) {
      return Quote.toStringLiteral(stringValue(value), quote);
    }
    if (code == TypeCode.BYTES.getValue() || code == TypeCode.PROTO.getValue()) {
      byte[] data = BytesFmt.decodeBase64Wire(stringValue(value));
      return Quote.toBytesLiteral(data, quote);
    }
    if (code == TypeCode.TIMESTAMP.getValue()) {
      return stringBasedLiteral("TIMESTAMP", stringValue(value), quote);
    }
    if (code == TypeCode.DATE.getValue()) {
      return stringBasedLiteral("DATE", stringValue(value), quote);
    }
    if (code == TypeCode.NUMERIC.getValue()) {
      return stringBasedLiteral("NUMERIC", numericWireString(value), quote);
    }
    if (code == TypeCode.JSON.getValue()) {
      return stringBasedLiteral("JSON", stringValue(value), quote);
    }
    if (code == TypeCode.INTERVAL.getValue()) {
      return Quote.sqlCastQuoted(stringValue(value), "INTERVAL", quote);
    }
    if (code == TypeCode.UUID.getValue()) {
      return Quote.sqlCastQuoted(stringValue(value), "UUID", quote);
    }
    throw new UnknownTypeException(String.valueOf(typ));
  }

  private static String formatScalarSpannerCli(Object typ, Object value) {
    validateScalarWire(typ, value);
    int code = typeCode(typ);
    if (code == TypeCode.BOOL.getValue()) {
      return boolValue(value) ? "true" : "false";
    }
    if (code == TypeCode.INT64.getValue()
        || code == TypeCode.ENUM.getValue()
        || code == TypeCode.STRING.getValue()
        || code == TypeCode.BYTES.getValue()
        || code == TypeCode.PROTO.getValue()
        || code == TypeCode.TIMESTAMP.getValue()
        || code == TypeCode.DATE.getValue()
        || code == TypeCode.INTERVAL.getValue()
        || code == TypeCode.UUID.getValue()
        || code == TypeCode.JSON.getValue()) {
      return stringValue(value);
    }
    if (code == TypeCode.FLOAT32.getValue()) {
      return FloatFmt.formatSpannerCliFloat(gcvFloat32(value), 32);
    }
    if (code == TypeCode.FLOAT64.getValue()) {
      return FloatFmt.formatSpannerCliFloat(gcvFloat64(value), 64);
    }
    if (code == TypeCode.NUMERIC.getValue()) {
      return trimSpannerCliNumericFraction(numericWireString(value));
    }
    throw new UnknownTypeException(String.valueOf(typ));
  }

  private static String formatProtoLiteral(
      Object typ, Object value, Quote.LiteralQuoteConfig quote, String nullString) {
    if (typeCode(typ) != TypeCode.PROTO.getValue()) {
      throw new UnknownTypeException(String.valueOf(typ));
    }
    if (isNullValue(value)) {
      return nullString;
    }
    requireStringWire(value, TypeCode.PROTO.getValue());
    byte[] data = BytesFmt.decodeBase64Wire(stringValue(value));
    String fqn = protoTypeFqn(typ);
    if (fqn.isEmpty()) {
      throw new EmptyTypeFQNException("empty type FQN for PROTO");
    }
    return "CAST(" + Quote.toBytesLiteral(data, quote) + " AS `" + fqn + "`)";
  }

  private static String formatEnumLiteral(Object typ, Object value, String nullString) {
    if (typeCode(typ) != TypeCode.ENUM.getValue()) {
      throw new UnknownTypeException(String.valueOf(typ));
    }
    if (isNullValue(value)) {
      return nullString;
    }
    requireStringWire(value, TypeCode.ENUM.getValue());
    String s = stringValue(value);
    try {
      new BigInteger(s, 10);
    } catch (NumberFormatException e) {
      throw new MalformedWireException("failed to parse enum wire payload " + quote(s));
    }
    String fqn = protoTypeFqn(typ);
    if (fqn.isEmpty()) {
      throw new EmptyTypeFQNException("empty type FQN for ENUM");
    }
    return "CAST(" + s + " AS `" + fqn + "`)";
  }

  private static String formatEnumSimple(Object typ, Object value, String nullString) {
    if (isNullValue(value)) {
      return nullString;
    }
    return formatScalarSimple(typ, value);
  }

  private static List<Object> getListValue(Object typ, Object value, int expectedCode) {
    if (!"list".equals(valueKind(value))) {
      throw new UnexpectedComplexValueKindException(
          "unexpected complex value kind for "
              + formatTypeCodeName(expectedCode)
              + ": "
              + quote(valueKind(value)));
    }
    return listValues(value);
  }

  public static String formatValue(Object typ, Object value, FormatConfig config) {
    return formatValue(typ, value, config, true);
  }

  public static String formatValue(
      Object typ, Object value, FormatConfig config, boolean toplevel) {
    if (isNullValue(value)) {
      return config.nullString();
    }

    int code = typeCode(typ);

    if (code == TypeCode.ARRAY.getValue()) {
      List<Object> elems = getListValue(typ, value, code);
      Object elemType = arrayElementType(typ);
      List<String> parts = new ArrayList<>();
      for (Object elem : elems) {
        parts.add(formatValue(elemType, elem, config, false));
      }
      String joined = String.join(", ", parts);
      if (config.preset() == Preset.LITERAL
          && toplevel
          && isComplexType(typeCode(elemType))) {
        return formatTypeVerbose(typ) + "[" + joined + "]";
      }
      return "[" + joined + "]";
    }

    if (code == TypeCode.STRUCT.getValue()) {
      List<Object> fieldVals = getListValue(typ, value, code);
      List<Object> fields = structFields(typ);
      if (fieldVals.size() != fields.size()) {
        throw new MismatchedFieldsException(
            "got " + fieldVals.size() + " values, want " + fields.size());
      }
      if (config.preset() == Preset.SIMPLE) {
        List<String> fieldStrs = new ArrayList<>();
        for (int i = 0; i < fields.size(); i++) {
          String rendered = formatValue(fieldType(fields.get(i)), fieldVals.get(i), config, false);
          String name = fieldName(fields.get(i));
          if (!name.isEmpty()) {
            fieldStrs.add(rendered + " AS " + name);
          } else {
            fieldStrs.add(rendered);
          }
        }
        return "(" + String.join(", ", fieldStrs) + ")";
      }
      List<String> fieldStrs = new ArrayList<>();
      for (int i = 0; i < fields.size(); i++) {
        fieldStrs.add(formatValue(fieldType(fields.get(i)), fieldVals.get(i), config, false));
      }
      String inner = String.join(", ", fieldStrs);
      if (config.preset() == Preset.LITERAL) {
        String prefix = toplevel ? formatTypeVerbose(typ) : "";
        return prefix + "(" + inner + ")";
      }
      if (config.preset() == Preset.SPANNER_CLI) {
        return "[" + inner + "]";
      }
      return "(" + inner + ")";
    }

    if (code == TypeCode.PROTO.getValue()) {
      if (config.preset() == Preset.LITERAL) {
        return formatProtoLiteral(typ, value, config.quote(), config.nullString());
      }
      requireStringWire(value, code);
      if (config.preset() == Preset.SPANNER_CLI) {
        return stringValue(value);
      }
      return BytesFmt.readableStringFromBase64Wire(stringValue(value));
    }

    if (code == TypeCode.ENUM.getValue()) {
      if (config.preset() == Preset.LITERAL) {
        return formatEnumLiteral(typ, value, config.nullString());
      }
      return formatEnumSimple(typ, value, config.nullString());
    }

    if (code == TypeCode.TYPE_CODE_UNSPECIFIED.getValue() || !isScalarType(code)) {
      throw new UnknownTypeException(String.valueOf(typ));
    }

    return switch (config.preset()) {
      case SIMPLE -> formatScalarSimple(typ, value);
      case LITERAL -> formatScalarLiteral(typ, value, config.quote());
      case SPANNER_CLI -> formatScalarSpannerCli(typ, value);
    };
  }

  public static List<String> formatRow(
      List<Object> types, List<Object> values, FormatConfig config) {
    if (types.size() != values.size()) {
      throw new IllegalArgumentException(
          "len(types)=" + types.size() + " != len(values)=" + values.size());
    }
    List<String> out = new ArrayList<>(types.size());
    for (int i = 0; i < types.size(); i++) {
      out.add(formatValue(types.get(i), values.get(i), config, true));
    }
    return out;
  }
}
