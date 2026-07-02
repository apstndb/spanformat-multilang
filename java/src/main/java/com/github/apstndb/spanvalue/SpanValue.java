package com.github.apstndb.spanvalue;

/**
 * Format Cloud Spanner types and column values.
 *
 * <p>Accepts protojson {@code Map} inputs and protobuf {@code Type}/{@code Value} objects.
 */
public final class SpanValue {
  private SpanValue() {}

  public static final String VERSION = "0.1.0";

  // Type formatting
  public static String formatType(Object typ) {
    return TypeFormat.formatType(typ, null);
  }

  public static String formatType(Object typ, TypeFormat.FormatOption option) {
    return TypeFormat.formatType(typ, option);
  }

  public static String formatTypeSimplest(Object typ) {
    return TypeFormat.formatTypeSimplest(typ);
  }

  public static String formatTypeSimple(Object typ) {
    return TypeFormat.formatTypeSimple(typ);
  }

  public static String formatTypeNormal(Object typ) {
    return TypeFormat.formatTypeNormal(typ);
  }

  public static String formatTypeVerbose(Object typ) {
    return TypeFormat.formatTypeVerbose(typ);
  }

  public static String formatTypeMoreVerbose(Object typ) {
    return TypeFormat.formatTypeMoreVerbose(typ);
  }

  public static String formatTypeVerboseAnnotationOmit(Object typ) {
    return TypeFormat.formatTypeVerboseAnnotationOmit(typ);
  }

  public static String formatTypeVerboseAnnotationPrimary(Object typ) {
    return TypeFormat.formatTypeVerboseAnnotationPrimary(typ);
  }

  // Value formatting
  public static String formatValue(Object typ, Object value, ValueFormat.FormatConfig config) {
    return ValueFormat.formatValue(typ, value, config);
  }

  public static java.util.List<String> formatRow(
      java.util.List<Object> types,
      java.util.List<Object> values,
      ValueFormat.FormatConfig config) {
    return ValueFormat.formatRow(types, values, config);
  }

  public static com.google.protobuf.Value encodeValue(Object typ, Object nativeValue) {
    return Gcvctor.encodeValue(typ, nativeValue);
  }

  public static com.google.spanner.v1.Type adaptClientType(Object clientType) {
    return ClientTypeAdapter.adapt(clientType);
  }

  public static java.util.List<String> formatResultRow(
      java.util.List<Object> types,
      java.util.List<Object> nativeValues,
      ValueFormat.FormatConfig config) {
    if (types.size() != nativeValues.size()) {
      throw new IllegalArgumentException(
          "len(types)=" + types.size() + " != len(values)=" + nativeValues.size());
    }
    java.util.List<Object> encoded = new java.util.ArrayList<>(nativeValues.size());
    for (int i = 0; i < types.size(); i++) {
      encoded.add(Gcvctor.encodeValue(types.get(i), nativeValues.get(i)));
    }
    return ValueFormat.formatRow(types, encoded, config);
  }

  public static ValueFormat.FormatConfig simpleFormatConfig() {
    return ValueFormat.simpleFormatConfig();
  }

  public static ValueFormat.FormatConfig literalFormatConfig() {
    return ValueFormat.literalFormatConfig();
  }

  public static ValueFormat.FormatConfig literalFormatConfig(Quote.LiteralQuoteConfig quote) {
    return ValueFormat.literalFormatConfig(quote);
  }

  public static ValueFormat.FormatConfig spannerCliFormatConfig() {
    return ValueFormat.spannerCliFormatConfig();
  }
}
