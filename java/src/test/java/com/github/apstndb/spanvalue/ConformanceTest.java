package com.github.apstndb.spanvalue;

import static org.junit.jupiter.api.Assertions.assertEquals;

import com.google.gson.Gson;
import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.reflect.TypeToken;
import com.google.protobuf.util.JsonFormat;
import com.google.spanner.v1.Type;
import com.google.protobuf.Value;
import java.io.IOException;
import java.io.Reader;
import java.nio.file.Path;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.stream.Stream;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.Arguments;
import org.junit.jupiter.params.provider.MethodSource;

class ConformanceTest {
  private static Map<String, Object> conformance;
  private static final Gson GSON = new Gson();
  private static final JsonFormat.Parser PROTO_PARSER =
      JsonFormat.parser().ignoringUnknownFields();

  @BeforeAll
  static void loadConformance() throws IOException {
    Path path =
        Path.of("..", "testdata", "conformance.json").toAbsolutePath().normalize();
    try (Reader reader = java.nio.file.Files.newBufferedReader(path)) {
      conformance =
          GSON.fromJson(reader, new TypeToken<Map<String, Object>>() {}.getType());
    }
  }

  private static Stream<Arguments> typeCasePresets() {
    List<String> presets =
        List.of(
            "simplest",
            "simple",
            "normal",
            "verbose",
            "more_verbose",
            "verbose_annotation_omit",
            "verbose_annotation_primary");
    @SuppressWarnings("unchecked")
    List<Map<String, Object>> cases = (List<Map<String, Object>>) conformance.get("type_cases");
    return presets.stream()
        .flatMap(
            preset ->
                cases.stream()
                    .map(
                        c ->
                            Arguments.of(
                                preset,
                                c.get("name"),
                                c.get("type"),
                                ((Map<String, String>) c.get("expected")).get(preset))));
  }

  @ParameterizedTest(name = "type {1} preset {0}")
  @MethodSource("typeCasePresets")
  void typeCases(String preset, String name, Object typeObj, String expected) {
    String got = formatTypePreset(preset, typeObj);
    assertEquals(expected, got, "type case " + name + " preset " + preset);
  }

  private static String formatTypePreset(String preset, Object typeObj) {
    return switch (preset) {
      case "simplest" -> SpanValue.formatTypeSimplest(typeObj);
      case "simple" -> SpanValue.formatTypeSimple(typeObj);
      case "normal" -> SpanValue.formatTypeNormal(typeObj);
      case "verbose" -> SpanValue.formatTypeVerbose(typeObj);
      case "more_verbose" -> SpanValue.formatTypeMoreVerbose(typeObj);
      case "verbose_annotation_omit" -> SpanValue.formatTypeVerboseAnnotationOmit(typeObj);
      case "verbose_annotation_primary" -> SpanValue.formatTypeVerboseAnnotationPrimary(typeObj);
      default -> throw new IllegalArgumentException("unknown preset: " + preset);
    };
  }

  private static Stream<Arguments> valueCasePresets() {
    List<String> presets = List.of("simple", "literal", "spanner_cli");
    @SuppressWarnings("unchecked")
    List<Map<String, Object>> cases = (List<Map<String, Object>>) conformance.get("value_cases");
    return presets.stream()
        .flatMap(
            preset ->
                cases.stream()
                    .map(
                        c ->
                            Arguments.of(
                                preset,
                                c.get("name"),
                                c.get("type"),
                                c.get("value"),
                                ((Map<String, String>) c.get("expected")).get(preset))));
  }

  @ParameterizedTest(name = "value {1} preset {0}")
  @MethodSource("valueCasePresets")
  void valueCases(String preset, String name, Object typeObj, Object valueObj, String expected) {
    ValueFormat.FormatConfig config =
        switch (preset) {
          case "simple" -> SpanValue.simpleFormatConfig();
          case "literal" -> SpanValue.literalFormatConfig();
          case "spanner_cli" -> SpanValue.spannerCliFormatConfig();
          default -> throw new IllegalArgumentException("unknown preset: " + preset);
        };
    String got = SpanValue.formatValue(typeObj, valueObj, config);
    assertEquals(expected, got, "value case " + name + " preset " + preset);
  }

  private static Stream<Arguments> literalQuotePolicies() {
    Map<String, Quote.LiteralQuoteConfig> policies = new LinkedHashMap<>();
    policies.put(
        "legacy_double",
        new Quote.LiteralQuoteConfig(Quote.QuoteStrategy.LEGACY, Quote.PreferredQuote.DOUBLE));
    policies.put(
        "legacy_single",
        new Quote.LiteralQuoteConfig(Quote.QuoteStrategy.LEGACY, Quote.PreferredQuote.SINGLE));
    policies.put(
        "always_double",
        new Quote.LiteralQuoteConfig(Quote.QuoteStrategy.ALWAYS, Quote.PreferredQuote.DOUBLE));
    policies.put(
        "always_single",
        new Quote.LiteralQuoteConfig(Quote.QuoteStrategy.ALWAYS, Quote.PreferredQuote.SINGLE));
    policies.put(
        "min_escape_double",
        new Quote.LiteralQuoteConfig(
            Quote.QuoteStrategy.MIN_ESCAPE, Quote.PreferredQuote.DOUBLE));
    policies.put(
        "min_escape_single",
        new Quote.LiteralQuoteConfig(
            Quote.QuoteStrategy.MIN_ESCAPE, Quote.PreferredQuote.SINGLE));

    @SuppressWarnings("unchecked")
    List<Map<String, Object>> cases = (List<Map<String, Object>>) conformance.get("value_cases");
    return policies.entrySet().stream()
        .flatMap(
            entry ->
                cases.stream()
                    .map(
                        c ->
                            Arguments.of(
                                entry.getKey(),
                                c.get("name"),
                                c.get("type"),
                                c.get("value"),
                                ((Map<String, Map<String, String>>) c.get("expected"))
                                    .get("literal_quotes")
                                    .get(entry.getKey()),
                                entry.getValue())));
  }

  @ParameterizedTest(name = "value {1} quote {0}")
  @MethodSource("literalQuotePolicies")
  void valueLiteralQuotes(
      String policyName,
      String name,
      Object typeObj,
      Object valueObj,
      String expected,
      Quote.LiteralQuoteConfig quote) {
    ValueFormat.FormatConfig config = SpanValue.literalFormatConfig(quote);
    String got = SpanValue.formatValue(typeObj, valueObj, config);
    assertEquals(expected, got, "value case " + name + " quote " + policyName);
  }

  /** Smoke test: protobuf Type objects via JsonFormat duck-typing. */
  @org.junit.jupiter.api.Test
  void protobufDuckTypingSmoke() throws Exception {
    @SuppressWarnings("unchecked")
    Map<String, Object> boolCase =
        ((List<Map<String, Object>>) conformance.get("type_cases")).stream()
            .filter(c -> "bool".equals(c.get("name")))
            .findFirst()
            .orElseThrow();
    JsonElement typeJson = GSON.toJsonTree(boolCase.get("type"));
    Type.Builder typeBuilder = Type.newBuilder();
    PROTO_PARSER.merge(typeJson.toString(), typeBuilder);
    assertEquals("BOOL", SpanValue.formatTypeSimple(typeBuilder.build()));
  }
}
