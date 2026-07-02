// Command gen-testdata generates testdata/conformance.json from the Go
// reference implementations (apstndb/spantype and apstndb/spanvalue).
// The generated file is the source of truth for every language port.
package main

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"log"
	"math"
	"os"

	"cloud.google.com/go/spanner"
	sppb "cloud.google.com/go/spanner/apiv1/spannerpb"
	"github.com/apstndb/spantype"
	"github.com/apstndb/spanvalue"
	"google.golang.org/protobuf/encoding/protojson"
	"google.golang.org/protobuf/proto"
	"google.golang.org/protobuf/types/known/structpb"
)

// --- Type constructors ---

func simpleT(code sppb.TypeCode) *sppb.Type { return &sppb.Type{Code: code} }

func annT(code sppb.TypeCode, ann sppb.TypeAnnotationCode) *sppb.Type {
	return &sppb.Type{Code: code, TypeAnnotation: ann}
}

func arrayT(elem *sppb.Type) *sppb.Type {
	return &sppb.Type{Code: sppb.TypeCode_ARRAY, ArrayElementType: elem}
}

func structT(fields ...*sppb.StructType_Field) *sppb.Type {
	return &sppb.Type{Code: sppb.TypeCode_STRUCT, StructType: &sppb.StructType{Fields: fields}}
}

func field(name string, typ *sppb.Type) *sppb.StructType_Field {
	return &sppb.StructType_Field{Name: name, Type: typ}
}

func protoT(fqn string) *sppb.Type {
	return &sppb.Type{Code: sppb.TypeCode_PROTO, ProtoTypeFqn: fqn}
}

func enumT(fqn string) *sppb.Type {
	return &sppb.Type{Code: sppb.TypeCode_ENUM, ProtoTypeFqn: fqn}
}

// --- Value constructors (wire shapes) ---

func nullV() *structpb.Value          { return structpb.NewNullValue() }
func boolV(b bool) *structpb.Value    { return structpb.NewBoolValue(b) }
func strV(s string) *structpb.Value   { return structpb.NewStringValue(s) }
func numV(f float64) *structpb.Value  { return structpb.NewNumberValue(f) }
func bytesV(b []byte) *structpb.Value { return strV(base64.StdEncoding.EncodeToString(b)) }

func listV(vs ...*structpb.Value) *structpb.Value {
	return structpb.NewListValue(&structpb.ListValue{Values: vs})
}

// f32 widens a float32 to the float64 that appears on the wire.
func f32(f float32) float64 { return float64(f) }

// --- Output schema ---

type typeExpected struct {
	Simplest                 string `json:"simplest"`
	Simple                   string `json:"simple"`
	Normal                   string `json:"normal"`
	Verbose                  string `json:"verbose"`
	MoreVerbose              string `json:"more_verbose"`
	VerboseAnnotationOmit    string `json:"verbose_annotation_omit"`
	VerboseAnnotationPrimary string `json:"verbose_annotation_primary"`
}

type typeCaseOut struct {
	Name     string          `json:"name"`
	Type     json.RawMessage `json:"type"`
	Expected typeExpected    `json:"expected"`
}

type literalQuotes struct {
	LegacyDouble    string `json:"legacy_double"`
	LegacySingle    string `json:"legacy_single"`
	AlwaysDouble    string `json:"always_double"`
	AlwaysSingle    string `json:"always_single"`
	MinEscapeDouble string `json:"min_escape_double"`
	MinEscapeSingle string `json:"min_escape_single"`
}

type valueExpected struct {
	Simple        string        `json:"simple"`
	Literal       string        `json:"literal"`
	SpannerCLI    string        `json:"spanner_cli"`
	LiteralQuotes literalQuotes `json:"literal_quotes"`
}

type valueCaseOut struct {
	Name     string          `json:"name"`
	Type     json.RawMessage `json:"type"`
	Value    json.RawMessage `json:"value"`
	Expected valueExpected   `json:"expected"`
}

type conformance struct {
	SpecVersion string         `json:"spec_version"`
	TypeCases   []typeCaseOut  `json:"type_cases"`
	ValueCases  []valueCaseOut `json:"value_cases"`
}

type typeCase struct {
	name string
	typ  *sppb.Type
}

type valueCase struct {
	name  string
	typ   *sppb.Type
	value *structpb.Value
}

func marshalProto(m proto.Message) json.RawMessage {
	b, err := protojson.Marshal(m)
	if err != nil {
		log.Fatalf("protojson: %v", err)
	}
	// protojson output has intentionally unstable whitespace; compact it
	// (preserving field order) so regeneration diffs stay minimal.
	var buf bytes.Buffer
	if err := json.Compact(&buf, b); err != nil {
		log.Fatalf("compact: %v", err)
	}
	out := bytes.Clone(buf.Bytes())
	// The bare JSON integer token -0 loses its sign in several languages'
	// JSON parsers (Python, Ruby, PHP parse it as integer zero); emit the
	// equivalent -0.0 so every conformance loader preserves negative zero.
	if bytes.Equal(out, []byte("-0")) {
		out = []byte("-0.0")
	}
	return json.RawMessage(out)
}

func gcvOf(t *sppb.Type, v *structpb.Value) spanner.GenericColumnValue {
	return spanner.GenericColumnValue{Type: t, Value: v}
}

func main() {
	tcs := typeCases()
	vcs := valueCases()

	out := conformance{SpecVersion: "0.1.0"}

	for _, tc := range tcs {
		out.TypeCases = append(out.TypeCases, typeCaseOut{
			Name:     tc.name,
			Type:     marshalProto(tc.typ),
			Expected: expectedForType(tc.typ),
		})
	}

	for _, vc := range vcs {
		exp, err := expectedForValue(vc.typ, vc.value)
		if err != nil {
			log.Fatalf("case %s: %v", vc.name, err)
		}
		out.ValueCases = append(out.ValueCases, valueCaseOut{
			Name:     vc.name,
			Type:     marshalProto(vc.typ),
			Value:    marshalProto(vc.value),
			Expected: exp,
		})
	}

	seen := map[string]bool{}
	for _, tc := range out.TypeCases {
		if seen["t:"+tc.Name] {
			log.Fatalf("duplicate type case name %q", tc.Name)
		}
		seen["t:"+tc.Name] = true
	}
	for _, vc := range out.ValueCases {
		if seen["v:"+vc.Name] {
			log.Fatalf("duplicate value case name %q", vc.Name)
		}
		seen["v:"+vc.Name] = true
	}

	b, err := json.MarshalIndent(out, "", "  ")
	if err != nil {
		log.Fatal(err)
	}
	b = append(b, '\n')
	path := "../../testdata/conformance.json"
	if len(os.Args) > 1 {
		path = os.Args[1]
	}
	if err := os.WriteFile(path, b, 0o644); err != nil {
		log.Fatal(err)
	}
	fmt.Printf("wrote %s: %d type cases, %d value cases\n", path, len(out.TypeCases), len(out.ValueCases))
}

func expectedForType(t *sppb.Type) typeExpected {
	omit := spantype.FormatOptionVerbose
	omit.TypeAnnotation = spantype.TypeAnnotationModeOmit
	primary := spantype.FormatOptionVerbose
	primary.TypeAnnotation = spantype.TypeAnnotationModePrimary
	return typeExpected{
		Simplest:                 spantype.FormatTypeSimplest(t),
		Simple:                   spantype.FormatTypeSimple(t),
		Normal:                   spantype.FormatTypeNormal(t),
		Verbose:                  spantype.FormatTypeVerbose(t),
		MoreVerbose:              spantype.FormatTypeMoreVerbose(t),
		VerboseAnnotationOmit:    spantype.FormatType(t, omit),
		VerboseAnnotationPrimary: spantype.FormatType(t, primary),
	}
}

func expectedForValue(t *sppb.Type, v *structpb.Value) (valueExpected, error) {
	gcv := gcvOf(t, v)

	simple, err := spanvalue.SimpleFormatConfig().FormatToplevelColumn(gcv)
	if err != nil {
		return valueExpected{}, fmt.Errorf("simple: %w", err)
	}
	literal, err := spanvalue.LiteralFormatConfig().FormatToplevelColumn(gcv)
	if err != nil {
		return valueExpected{}, fmt.Errorf("literal: %w", err)
	}
	cli, err := spanvalue.SpannerCLICompatibleFormatConfig().FormatToplevelColumn(gcv)
	if err != nil {
		return valueExpected{}, fmt.Errorf("spanner_cli: %w", err)
	}

	quote := func(s spanvalue.QuoteStrategy, p spanvalue.PreferredQuote) (string, error) {
		return spanvalue.LiteralFormatConfigWithQuote(spanvalue.LiteralQuoteConfig{
			Strategy:       s,
			PreferredQuote: p,
		}).FormatToplevelColumn(gcv)
	}
	var lq literalQuotes
	for _, e := range []struct {
		dst *string
		s   spanvalue.QuoteStrategy
		p   spanvalue.PreferredQuote
	}{
		{&lq.LegacyDouble, spanvalue.QuoteLegacy, spanvalue.PreferredDoubleQuote},
		{&lq.LegacySingle, spanvalue.QuoteLegacy, spanvalue.PreferredSingleQuote},
		{&lq.AlwaysDouble, spanvalue.QuoteAlways, spanvalue.PreferredDoubleQuote},
		{&lq.AlwaysSingle, spanvalue.QuoteAlways, spanvalue.PreferredSingleQuote},
		{&lq.MinEscapeDouble, spanvalue.QuoteMinEscape, spanvalue.PreferredDoubleQuote},
		{&lq.MinEscapeSingle, spanvalue.QuoteMinEscape, spanvalue.PreferredSingleQuote},
	} {
		s, err := quote(e.s, e.p)
		if err != nil {
			return valueExpected{}, fmt.Errorf("literal quotes: %w", err)
		}
		*e.dst = s
	}

	if lq.LegacyDouble != literal {
		return valueExpected{}, fmt.Errorf("legacy_double %q != default literal %q", lq.LegacyDouble, literal)
	}

	return valueExpected{Simple: simple, Literal: literal, SpannerCLI: cli, LiteralQuotes: lq}, nil
}

func typeCases() []typeCase {
	book := "examples.Book"
	genre := "examples.Genre"
	return []typeCase{
		{"unspecified", simpleT(sppb.TypeCode_TYPE_CODE_UNSPECIFIED)},
		{"bool", simpleT(sppb.TypeCode_BOOL)},
		{"int64", simpleT(sppb.TypeCode_INT64)},
		{"float32", simpleT(sppb.TypeCode_FLOAT32)},
		{"float64", simpleT(sppb.TypeCode_FLOAT64)},
		{"timestamp", simpleT(sppb.TypeCode_TIMESTAMP)},
		{"date", simpleT(sppb.TypeCode_DATE)},
		{"string", simpleT(sppb.TypeCode_STRING)},
		{"bytes", simpleT(sppb.TypeCode_BYTES)},
		{"numeric", simpleT(sppb.TypeCode_NUMERIC)},
		{"json", simpleT(sppb.TypeCode_JSON)},
		{"interval", simpleT(sppb.TypeCode_INTERVAL)},
		{"uuid", simpleT(sppb.TypeCode_UUID)},
		{"proto", protoT(book)},
		{"proto_no_package", protoT("Book")},
		{"enum", enumT(genre)},
		{"array_int64", arrayT(simpleT(sppb.TypeCode_INT64))},
		{"array_array_int64", arrayT(arrayT(simpleT(sppb.TypeCode_INT64)))},
		{"array_proto", arrayT(protoT(book))},
		{"array_struct", arrayT(structT(field("n", simpleT(sppb.TypeCode_INT64))))},
		{"struct_empty", structT()},
		{"struct_named", structT(
			field("n", simpleT(sppb.TypeCode_INT64)),
			field("s", simpleT(sppb.TypeCode_STRING)),
		)},
		{"struct_unnamed", structT(
			field("", simpleT(sppb.TypeCode_INT64)),
			field("", simpleT(sppb.TypeCode_STRING)),
		)},
		{"struct_readme_example", structT(
			field("arr", arrayT(structT(field("n", simpleT(sppb.TypeCode_INT64))))),
			field("proto", protoT(book)),
		)},
		{"pg_numeric", annT(sppb.TypeCode_NUMERIC, sppb.TypeAnnotationCode_PG_NUMERIC)},
		{"pg_jsonb", annT(sppb.TypeCode_JSON, sppb.TypeAnnotationCode_PG_JSONB)},
		{"pg_oid", annT(sppb.TypeCode_INT64, sppb.TypeAnnotationCode_PG_OID)},
		{"array_pg_numeric", arrayT(annT(sppb.TypeCode_NUMERIC, sppb.TypeAnnotationCode_PG_NUMERIC))},
		{"struct_with_pg_field", structT(
			field("n", annT(sppb.TypeCode_NUMERIC, sppb.TypeAnnotationCode_PG_NUMERIC)),
		)},
		{"unknown_positive", simpleT(sppb.TypeCode(999))},
		{"unknown_negative", simpleT(sppb.TypeCode(-1))},
	}
}

func valueCases() []valueCase {
	book := "examples.Book"
	genre := "examples.Genre"

	int64T := simpleT(sppb.TypeCode_INT64)
	stringT := simpleT(sppb.TypeCode_STRING)
	float64T := simpleT(sppb.TypeCode_FLOAT64)
	float32T := simpleT(sppb.TypeCode_FLOAT32)
	bytesT := simpleT(sppb.TypeCode_BYTES)

	cases := []valueCase{
		// BOOL
		{"bool_true", simpleT(sppb.TypeCode_BOOL), boolV(true)},
		{"bool_false", simpleT(sppb.TypeCode_BOOL), boolV(false)},
		{"bool_null", simpleT(sppb.TypeCode_BOOL), nullV()},

		// INT64
		{"int64_positive", int64T, strV("1")},
		{"int64_min", int64T, strV("-9223372036854775808")},
		{"int64_max", int64T, strV("9223372036854775807")},
		{"int64_null", int64T, nullV()},

		// FLOAT64
		{"float64_pi", float64T, numV(3.14)},
		{"float64_zero", float64T, numV(0)},
		{"float64_neg_zero", float64T, numV(math.Copysign(0, -1))},
		{"float64_one", float64T, numV(1)},
		{"float64_e5", float64T, numV(100000)},
		{"float64_e6", float64T, numV(1000000)},
		{"float64_e21", float64T, numV(1e21)},
		{"float64_e_minus_4", float64T, numV(0.0001)},
		{"float64_e_minus_5", float64T, numV(0.00001)},
		{"float64_max", float64T, numV(math.MaxFloat64)},
		{"float64_denormal_min", float64T, numV(5e-324)},
		{"float64_point_3", float64T, numV(0.3)},
		{"float64_seventeen_digits", float64T, numV(0.30000000000000004)},
		{"float64_fraction", float64T, numV(123456.789)},
		{"float64_neg", float64T, numV(-42.5)},
		{"float64_nan", float64T, strV("NaN")},
		{"float64_inf", float64T, strV("Infinity")},
		{"float64_neg_inf", float64T, strV("-Infinity")},
		{"float64_null", float64T, nullV()},

		// FLOAT32
		{"float32_pi", float32T, numV(f32(3.14))},
		{"float32_point_1", float32T, numV(f32(0.1))},
		{"float32_max", float32T, numV(f32(math.MaxFloat32))},
		{"float32_min_normal", float32T, numV(f32(1.1754944e-38))},
		{"float32_one_and_half", float32T, numV(f32(1.5))},
		{"float32_e6", float32T, numV(f32(1000000))},
		{"float32_nan", float32T, strV("NaN")},
		{"float32_inf", float32T, strV("Infinity")},
		{"float32_neg_inf", float32T, strV("-Infinity")},
		{"float32_null", float32T, nullV()},

		// STRING
		{"string_empty", stringT, strV("")},
		{"string_plain", stringT, strV("foo")},
		{"string_single_quote", stringT, strV("It's")},
		{"string_double_quote", stringT, strV(`say "hi"`)},
		{"string_both_quotes", stringT, strV(`a''b"c`)},
		{"string_newline", stringT, strV("line\nbreak")},
		{"string_tab_cr", stringT, strV("tab\there\rcr")},
		{"string_backslash", stringT, strV(`back\slash`)},
		{"string_japanese", stringT, strV("日本語")},
		{"string_emoji", stringT, strV("😀")},
		{"string_control", stringT, strV("a\x01b")},
		{"string_nbsp", stringT, strV("a b")},
		{"string_zero_width_space", stringT, strV("a​b")},
		{"string_combining_mark", stringT, strV("é")},
		{"string_null", stringT, nullV()},

		// BYTES
		{"bytes_empty", bytesT, bytesV(nil)},
		{"bytes_ascii", bytesT, bytesV([]byte("abc"))},
		{"bytes_quotes", bytesT, bytesV([]byte(`'"`))},
		{"bytes_binary", bytesT, bytesV([]byte{0x00, 0x01, 0xff})},
		{"bytes_newline", bytesT, bytesV([]byte("abc\ndef"))},
		{"bytes_backslash", bytesT, bytesV([]byte(`a\b`))},
		{"bytes_null", bytesT, nullV()},

		// TIMESTAMP
		{"timestamp_nanos", simpleT(sppb.TypeCode_TIMESTAMP), strV("2024-01-02T03:04:05.123456789Z")},
		{"timestamp_seconds", simpleT(sppb.TypeCode_TIMESTAMP), strV("2024-01-02T03:04:05Z")},
		{"timestamp_null", simpleT(sppb.TypeCode_TIMESTAMP), nullV()},

		// DATE
		{"date", simpleT(sppb.TypeCode_DATE), strV("2024-01-02")},
		{"date_null", simpleT(sppb.TypeCode_DATE), nullV()},

		// NUMERIC
		{"numeric_pi", simpleT(sppb.TypeCode_NUMERIC), strV("3.14")},
		{"numeric_trailing_zeros", simpleT(sppb.TypeCode_NUMERIC), strV("-1.230000000")},
		{"numeric_ten_point_zero", simpleT(sppb.TypeCode_NUMERIC), strV("10.0")},
		{"numeric_integer", simpleT(sppb.TypeCode_NUMERIC), strV("10")},
		{"numeric_small", simpleT(sppb.TypeCode_NUMERIC), strV("0.000000001")},
		{"numeric_null", simpleT(sppb.TypeCode_NUMERIC), nullV()},
		{"pg_numeric", annT(sppb.TypeCode_NUMERIC, sppb.TypeAnnotationCode_PG_NUMERIC), strV("3.140")},
		{"pg_numeric_nan", annT(sppb.TypeCode_NUMERIC, sppb.TypeAnnotationCode_PG_NUMERIC), strV("NaN")},
		{"pg_numeric_null", annT(sppb.TypeCode_NUMERIC, sppb.TypeAnnotationCode_PG_NUMERIC), nullV()},

		// JSON
		{"json_object", simpleT(sppb.TypeCode_JSON), strV(`{"a":1}`)},
		{"json_array", simpleT(sppb.TypeCode_JSON), strV(`[1,2,3]`)},
		{"json_string", simpleT(sppb.TypeCode_JSON), strV(`"str"`)},
		{"json_json_null", simpleT(sppb.TypeCode_JSON), strV(`null`)},
		{"json_null", simpleT(sppb.TypeCode_JSON), nullV()},
		{"pg_jsonb", annT(sppb.TypeCode_JSON, sppb.TypeAnnotationCode_PG_JSONB), strV(`{"b": 2}`)},

		// INTERVAL
		{"interval", simpleT(sppb.TypeCode_INTERVAL), strV("P1Y2M3DT4H5M6.789S")},
		{"interval_null", simpleT(sppb.TypeCode_INTERVAL), nullV()},

		// UUID
		{"uuid", simpleT(sppb.TypeCode_UUID), strV("01234567-89ab-cdef-0123-456789abcdef")},
		{"uuid_null", simpleT(sppb.TypeCode_UUID), nullV()},

		// ENUM
		{"enum", enumT(genre), strV("42")},
		{"enum_null", enumT(genre), nullV()},

		// PROTO
		{"proto", protoT(book), bytesV([]byte{0x08, 0x96, 0x01})},
		{"proto_readable", protoT(book), bytesV([]byte("abc"))},
		{"proto_null", protoT(book), nullV()},

		// ARRAY
		{"array_int64", arrayT(int64T), listV(strV("1"), strV("2"), strV("3"))},
		{"array_int64_empty", arrayT(int64T), listV()},
		{"array_int64_with_null", arrayT(int64T), listV(strV("1"), nullV())},
		{"array_int64_null", arrayT(int64T), nullV()},
		{"array_string", arrayT(stringT), listV(strV("a"), strV(`b"c`))},
		{"array_bytes", arrayT(bytesT), listV(bytesV([]byte{0x00}), bytesV([]byte("ok")))},
		{"array_float64_nonfinite", arrayT(float64T), listV(strV("NaN"), strV("Infinity"), strV("-Infinity"), numV(1.5))},
		{"array_enum", arrayT(enumT(genre)), listV(strV("1"), nullV())},
		{"array_struct", arrayT(structT(
			field("n", int64T),
			field("region", stringT),
		)), listV(
			listV(strV("1"), strV("east")),
		)},
		{"array_struct_empty", arrayT(structT(field("n", int64T))), listV()},
		{"array_array_int64", arrayT(arrayT(int64T)), listV(
			listV(strV("1"), strV("2")),
			nullV(),
		)},

		// STRUCT
		{"struct_named", structT(
			field("n", int64T),
			field("s", stringT),
		), listV(strV("1"), strV("foo"))},
		{"struct_unnamed", structT(
			field("", int64T),
			field("", stringT),
		), listV(strV("1"), strV("foo"))},
		{"struct_empty", structT(), listV()},
		{"struct_null", structT(field("n", int64T)), nullV()},
		{"struct_with_null_field", structT(
			field("n", int64T),
			field("s", stringT),
		), listV(nullV(), strV("x"))},
		{"struct_nested", structT(
			field("arr", arrayT(int64T)),
			field("inner", structT(field("b", simpleT(sppb.TypeCode_BOOL)))),
		), listV(
			listV(strV("10"), strV("20")),
			listV(boolV(true)),
		)},
	}
	return cases
}
