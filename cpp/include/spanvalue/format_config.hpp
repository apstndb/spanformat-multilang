#pragma once

#include <cmath>
#include <cstdint>
#include <limits>
#include <sstream>
#include <string>
#include <vector>

#include "spanvalue/bytes_fmt.hpp"
#include "spanvalue/codes.hpp"
#include "spanvalue/errors.hpp"
#include "spanvalue/float_fmt.hpp"
#include "spanvalue/proto_json.hpp"
#include "spanvalue/quote.hpp"
#include "spanvalue/type_format.hpp"

namespace spanvalue {

enum class Preset { kSimple = 0, kLiteral = 1, kSpannerCli = 2 };

struct FormatConfig {
  Preset preset = Preset::kSimple;
  std::string null_string = "<null>";
  LiteralQuoteConfig quote{};

  FormatConfig() { validate(); }

  FormatConfig(Preset p, std::string null_str, LiteralQuoteConfig q = {})
      : preset(p), null_string(std::move(null_str)), quote(std::move(q)) {
    validate();
  }

  void validate() const {
    if (null_string.empty()) {
      throw EmptyNullStringError("null_string must not be empty");
    }
  }

  FormatConfig with_null_string(std::string ns) const {
    return FormatConfig(preset, std::move(ns), quote);
  }
};

inline FormatConfig simple_format_config(const std::string& null_string = "<null>") {
  return FormatConfig(Preset::kSimple, null_string);
}

inline FormatConfig literal_format_config(const LiteralQuoteConfig& quote = {},
                                          const std::string& null_string = "NULL") {
  return FormatConfig(Preset::kLiteral, null_string, normalize_literal_quote(quote));
}

inline FormatConfig spanner_cli_format_config(const std::string& null_string = "NULL") {
  return FormatConfig(Preset::kSpannerCli, null_string);
}

inline bool is_complex_type(int code) {
  return code == static_cast<int>(TypeCode::kArray) || code == static_cast<int>(TypeCode::kStruct);
}

inline bool is_scalar_type(int code) {
  switch (code) {
    case static_cast<int>(TypeCode::kBool):
    case static_cast<int>(TypeCode::kInt64):
    case static_cast<int>(TypeCode::kEnum):
    case static_cast<int>(TypeCode::kFloat32):
    case static_cast<int>(TypeCode::kFloat64):
    case static_cast<int>(TypeCode::kString):
    case static_cast<int>(TypeCode::kBytes):
    case static_cast<int>(TypeCode::kProto):
    case static_cast<int>(TypeCode::kTimestamp):
    case static_cast<int>(TypeCode::kDate):
    case static_cast<int>(TypeCode::kNumeric):
    case static_cast<int>(TypeCode::kJson):
    case static_cast<int>(TypeCode::kInterval):
    case static_cast<int>(TypeCode::kUuid):
      return true;
    default:
      return false;
  }
}

inline void require_string_wire(const Json& value, int code) {
  if (value_kind(value) != ValueKind::kString) {
    throw MalformedWireError(format_type_code(code) + " value kind " + value_kind_name(value_kind(value)));
  }
}

inline void require_bool_wire(const Json& value, int code) {
  if (value_kind(value) != ValueKind::kBool) {
    throw MalformedWireError(format_type_code(code) + " value kind " + value_kind_name(value_kind(value)));
  }
}

inline void validate_float_wire(const Json& value, int code) {
  const ValueKind kind = value_kind(value);
  if (kind == ValueKind::kNumber) {
    return;
  }
  if (kind == ValueKind::kString) {
    const std::string s = string_value(value);
    if (s == "NaN" || s == "Infinity" || s == "-Infinity") {
      return;
    }
    throw MalformedWireError(format_type_code(code) + " unexpected float string \"" + s + "\"");
  }
  throw MalformedWireError(format_type_code(code) + " value kind " + value_kind_name(kind));
}

inline double gcv_float64(const Json& value) {
  const ValueKind kind = value_kind(value);
  if (kind == ValueKind::kNumber) {
    return number_value(value);
  }
  if (kind == ValueKind::kString) {
    const std::string s = string_value(value);
    if (s == "NaN") {
      return std::numeric_limits<double>::quiet_NaN();
    }
    if (s == "Infinity") {
      return std::numeric_limits<double>::infinity();
    }
    if (s == "-Infinity") {
      return -std::numeric_limits<double>::infinity();
    }
    throw MalformedWireError("FLOAT64 unexpected float string \"" + s + "\"");
  }
  throw MalformedWireError("FLOAT64 value kind " + value_kind_name(kind));
}

inline double gcv_float32(const Json& value) { return narrow_float32(gcv_float64(value)); }

inline void validate_scalar_wire(const Json& typ, const Json& value) {
  if (typ.is_null()) {
    throw MalformedWireError("nil type with value kind " + value_kind_name(value_kind(value)));
  }
  if (is_null_value(value)) {
    throw MalformedWireError(format_type_code(type_code(typ)) + " unexpected null value");
  }
  const int code = type_code(typ);
  if (code == static_cast<int>(TypeCode::kBool)) {
    require_bool_wire(value, code);
  } else if (code == static_cast<int>(TypeCode::kInt64) ||
             code == static_cast<int>(TypeCode::kEnum) ||
             code == static_cast<int>(TypeCode::kString) ||
             code == static_cast<int>(TypeCode::kBytes) ||
             code == static_cast<int>(TypeCode::kProto) ||
             code == static_cast<int>(TypeCode::kTimestamp) ||
             code == static_cast<int>(TypeCode::kDate) ||
             code == static_cast<int>(TypeCode::kNumeric) ||
             code == static_cast<int>(TypeCode::kInterval) ||
             code == static_cast<int>(TypeCode::kUuid) ||
             code == static_cast<int>(TypeCode::kJson)) {
    require_string_wire(value, code);
  } else if (code == static_cast<int>(TypeCode::kFloat32) ||
             code == static_cast<int>(TypeCode::kFloat64)) {
    validate_float_wire(value, code);
  } else if (code == static_cast<int>(TypeCode::kUnspecified)) {
    throw UnknownTypeError(typ.dump());
  } else if (!is_scalar_type(code)) {
    throw UnknownTypeError(typ.dump());
  }
}

inline std::string trim_spanner_cli_numeric_fraction(const std::string& s) {
  if (s.find('.') == std::string::npos) {
    return s;
  }
  std::string out = s;
  while (!out.empty() && out.back() == '0') {
    out.pop_back();
  }
  if (!out.empty() && out.back() == '.') {
    out.pop_back();
  }
  return out;
}

inline std::string numeric_wire_string(const Json& value) { return string_value(value); }

inline std::string string_based_literal(const std::string& type_name, const std::string& payload,
                                        const LiteralQuoteConfig& quote) {
  return type_name + " " + to_string_literal(payload, quote);
}

inline bool int64_in_range(const std::string& s) {
  if (!is_decimal_int_string(s)) {
    return false;
  }
  if (s == "0" || s == "-0") {
    return true;
  }
  static const std::string kMin = std::to_string(std::numeric_limits<int64_t>::min());
  static const std::string kMax = std::to_string(std::numeric_limits<int64_t>::max());
  const std::size_t len = s.size();
  const std::size_t min_len = kMin.size();
  const std::size_t max_len = kMax.size();
  if (s[0] == '-') {
    if (len > min_len) {
      return false;
    }
    if (len < min_len) {
      return true;
    }
    return s >= kMin;
  }
  if (len > max_len) {
    return false;
  }
  if (len < max_len) {
    return true;
  }
  return s <= kMax;
}

inline std::string format_scalar_simple(const Json& typ, const Json& value) {
  validate_scalar_wire(typ, value);
  const int code = type_code(typ);
  if (code == static_cast<int>(TypeCode::kBool)) {
    return bool_value(value) ? "true" : "false";
  }
  if (code == static_cast<int>(TypeCode::kInt64) || code == static_cast<int>(TypeCode::kEnum) ||
      code == static_cast<int>(TypeCode::kString) ||
      code == static_cast<int>(TypeCode::kTimestamp) ||
      code == static_cast<int>(TypeCode::kDate) || code == static_cast<int>(TypeCode::kJson) ||
      code == static_cast<int>(TypeCode::kInterval) || code == static_cast<int>(TypeCode::kUuid)) {
    return string_value(value);
  }
  if (code == static_cast<int>(TypeCode::kFloat32)) {
    return format_go_g(gcv_float32(value), 32);
  }
  if (code == static_cast<int>(TypeCode::kFloat64)) {
    return format_go_g(gcv_float64(value), 64);
  }
  if (code == static_cast<int>(TypeCode::kBytes) || code == static_cast<int>(TypeCode::kProto)) {
    return readable_string_from_base64_wire(string_value(value));
  }
  if (code == static_cast<int>(TypeCode::kNumeric)) {
    return numeric_wire_string(value);
  }
  throw UnknownTypeError(typ.dump());
}

inline std::string format_scalar_literal(const Json& typ, const Json& value,
                                         const LiteralQuoteConfig& quote) {
  validate_scalar_wire(typ, value);
  const int code = type_code(typ);
  if (code == static_cast<int>(TypeCode::kBool)) {
    return bool_value(value) ? "true" : "false";
  }
  if (code == static_cast<int>(TypeCode::kInt64)) {
    const std::string s = string_value(value);
    if (!is_decimal_int_string(s) || !int64_in_range(s)) {
      throw MalformedWireError("invalid INT64 wire \"" + s + "\"");
    }
    return s;
  }
  if (code == static_cast<int>(TypeCode::kFloat32)) {
    return float32_to_literal(gcv_float32(value), quote);
  }
  if (code == static_cast<int>(TypeCode::kFloat64)) {
    return float64_to_literal(gcv_float64(value), quote);
  }
  if (code == static_cast<int>(TypeCode::kString)) {
    return to_string_literal(string_value(value), quote);
  }
  if (code == static_cast<int>(TypeCode::kBytes) || code == static_cast<int>(TypeCode::kProto)) {
    return to_bytes_literal(decode_base64_wire(string_value(value)), quote);
  }
  if (code == static_cast<int>(TypeCode::kTimestamp)) {
    return string_based_literal("TIMESTAMP", string_value(value), quote);
  }
  if (code == static_cast<int>(TypeCode::kDate)) {
    return string_based_literal("DATE", string_value(value), quote);
  }
  if (code == static_cast<int>(TypeCode::kNumeric)) {
    return string_based_literal("NUMERIC", numeric_wire_string(value), quote);
  }
  if (code == static_cast<int>(TypeCode::kJson)) {
    return string_based_literal("JSON", string_value(value), quote);
  }
  if (code == static_cast<int>(TypeCode::kInterval)) {
    return sql_cast_quoted(string_value(value), "INTERVAL", quote);
  }
  if (code == static_cast<int>(TypeCode::kUuid)) {
    return sql_cast_quoted(string_value(value), "UUID", quote);
  }
  throw UnknownTypeError(typ.dump());
}

inline std::string format_scalar_spanner_cli(const Json& typ, const Json& value) {
  validate_scalar_wire(typ, value);
  const int code = type_code(typ);
  if (code == static_cast<int>(TypeCode::kBool)) {
    return bool_value(value) ? "true" : "false";
  }
  if (code == static_cast<int>(TypeCode::kInt64) || code == static_cast<int>(TypeCode::kEnum) ||
      code == static_cast<int>(TypeCode::kString) ||
      code == static_cast<int>(TypeCode::kBytes) || code == static_cast<int>(TypeCode::kProto) ||
      code == static_cast<int>(TypeCode::kTimestamp) ||
      code == static_cast<int>(TypeCode::kDate) || code == static_cast<int>(TypeCode::kInterval) ||
      code == static_cast<int>(TypeCode::kUuid) || code == static_cast<int>(TypeCode::kJson)) {
    return string_value(value);
  }
  if (code == static_cast<int>(TypeCode::kFloat32)) {
    return format_spanner_cli_float(gcv_float32(value), 32);
  }
  if (code == static_cast<int>(TypeCode::kFloat64)) {
    return format_spanner_cli_float(gcv_float64(value), 64);
  }
  if (code == static_cast<int>(TypeCode::kNumeric)) {
    return trim_spanner_cli_numeric_fraction(numeric_wire_string(value));
  }
  throw UnknownTypeError(typ.dump());
}

inline std::string format_proto_literal(const Json& typ, const Json& value,
                                          const LiteralQuoteConfig& quote,
                                          const std::string& null_string) {
  if (type_code(typ) != static_cast<int>(TypeCode::kProto)) {
    throw UnknownTypeError(typ.dump());
  }
  if (is_null_value(value)) {
    return null_string;
  }
  require_string_wire(value, static_cast<int>(TypeCode::kProto));
  const std::vector<uint8_t> data = decode_base64_wire(string_value(value));
  const std::string fqn = proto_type_fqn(typ);
  if (fqn.empty()) {
    throw EmptyTypeFQNError("empty type FQN for PROTO");
  }
  return "CAST(" + to_bytes_literal(data, quote) + " AS `" + fqn + "`)";
}

inline std::string format_enum_literal(const Json& typ, const Json& value,
                                       const std::string& null_string) {
  if (type_code(typ) != static_cast<int>(TypeCode::kEnum)) {
    throw UnknownTypeError(typ.dump());
  }
  if (is_null_value(value)) {
    return null_string;
  }
  require_string_wire(value, static_cast<int>(TypeCode::kEnum));
  const std::string s = string_value(value);
  if (!is_decimal_int_string(s)) {
    throw MalformedWireError("failed to parse enum wire payload \"" + s + "\"");
  }
  const std::string fqn = proto_type_fqn(typ);
  if (fqn.empty()) {
    throw EmptyTypeFQNError("empty type FQN for ENUM");
  }
  return "CAST(" + s + " AS `" + fqn + "`)";
}

inline std::string format_enum_simple(const Json& typ, const Json& value,
                                      const std::string& null_string) {
  if (is_null_value(value)) {
    return null_string;
  }
  return format_scalar_simple(typ, value);
}

inline std::vector<Json> get_list_value(const Json& typ, const Json& value, int expected_code) {
  if (value_kind(value) != ValueKind::kList) {
    throw UnexpectedComplexValueKindError("unexpected complex value kind for " +
                                          format_type_code(expected_code) + ": " +
                                          value_kind_name(value_kind(value)));
  }
  return list_values(value);
}

inline std::string format_value(const Json& typ, const Json& value, const FormatConfig& config,
                                bool toplevel = true) {
  if (is_null_value(value)) {
    return config.null_string;
  }

  const int code = type_code(typ);

  if (code == static_cast<int>(TypeCode::kArray)) {
    const std::vector<Json> elems = get_list_value(typ, value, code);
    const Json elem_type = array_element_type(typ);
    std::vector<std::string> parts;
    parts.reserve(elems.size());
    for (const Json& elem : elems) {
      parts.push_back(format_value(elem_type, elem, config, false));
    }
    std::string joined;
    for (std::size_t i = 0; i < parts.size(); ++i) {
      if (i > 0) {
        joined += ", ";
      }
      joined += parts[i];
    }
    if (config.preset == Preset::kLiteral && toplevel &&
        is_complex_type(type_code(elem_type))) {
      return format_type_verbose(typ) + "[" + joined + "]";
    }
    return "[" + joined + "]";
  }

  if (code == static_cast<int>(TypeCode::kStruct)) {
    const std::vector<Json> field_vals = get_list_value(typ, value, code);
    const std::vector<Json> fields = struct_fields(typ);
    if (field_vals.size() != fields.size()) {
      throw MismatchedFieldsError("got " + std::to_string(field_vals.size()) + " values, want " +
                                  std::to_string(fields.size()));
    }
    if (config.preset == Preset::kSimple) {
      std::vector<std::string> field_strs;
      field_strs.reserve(fields.size());
      for (std::size_t i = 0; i < fields.size(); ++i) {
        std::string rendered = format_value(field_type(fields[i]), field_vals[i], config, false);
        const std::string name = field_name(fields[i]);
        if (!name.empty()) {
          field_strs.push_back(rendered + " AS " + name);
        } else {
          field_strs.push_back(std::move(rendered));
        }
      }
      std::string inner;
      for (std::size_t i = 0; i < field_strs.size(); ++i) {
        if (i > 0) {
          inner += ", ";
        }
        inner += field_strs[i];
      }
      return "(" + inner + ")";
    }
    std::vector<std::string> field_strs;
    field_strs.reserve(fields.size());
    for (std::size_t i = 0; i < fields.size(); ++i) {
      field_strs.push_back(format_value(field_type(fields[i]), field_vals[i], config, false));
    }
    std::string inner;
    for (std::size_t i = 0; i < field_strs.size(); ++i) {
      if (i > 0) {
        inner += ", ";
      }
      inner += field_strs[i];
    }
    if (config.preset == Preset::kLiteral) {
      const std::string prefix = toplevel ? format_type_verbose(typ) : "";
      return prefix + "(" + inner + ")";
    }
    if (config.preset == Preset::kSpannerCli) {
      return "[" + inner + "]";
    }
    return "(" + inner + ")";
  }

  if (code == static_cast<int>(TypeCode::kProto)) {
    if (config.preset == Preset::kLiteral) {
      return format_proto_literal(typ, value, config.quote, config.null_string);
    }
    require_string_wire(value, code);
    if (config.preset == Preset::kSpannerCli) {
      return string_value(value);
    }
    return readable_string_from_base64_wire(string_value(value));
  }

  if (code == static_cast<int>(TypeCode::kEnum)) {
    if (config.preset == Preset::kLiteral) {
      return format_enum_literal(typ, value, config.null_string);
    }
    return format_enum_simple(typ, value, config.null_string);
  }

  if (code == static_cast<int>(TypeCode::kUnspecified) || !is_scalar_type(code)) {
    throw UnknownTypeError(typ.dump());
  }

  if (config.preset == Preset::kSimple) {
    return format_scalar_simple(typ, value);
  }
  if (config.preset == Preset::kLiteral) {
    return format_scalar_literal(typ, value, config.quote);
  }
  return format_scalar_spanner_cli(typ, value);
}

inline std::vector<std::string> format_row(const std::vector<Json>& types,
                                           const std::vector<Json>& values,
                                           const FormatConfig& config) {
  if (types.size() != values.size()) {
    throw std::invalid_argument("len(types)=" + std::to_string(types.size()) + " != len(values)=" +
                                std::to_string(values.size()));
  }
  std::vector<std::string> out;
  out.reserve(types.size());
  for (std::size_t i = 0; i < types.size(); ++i) {
    out.push_back(format_value(types[i], values[i], config, true));
  }
  return out;
}

}  // namespace spanvalue
