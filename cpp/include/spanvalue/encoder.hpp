#pragma once

#include <cmath>
#include <cstdint>
#include <limits>
#include <string>
#include <vector>

#include <nlohmann/json.hpp>

#include "spanvalue/bytes_fmt.hpp"
#include "spanvalue/codes.hpp"
#include "spanvalue/errors.hpp"
#include "spanvalue/format_config.hpp"
#include "spanvalue/proto_json.hpp"

namespace spanvalue {

inline bool is_native_null(const Json& value) { return value.is_null(); }

inline Json encode_float64(double v) {
  if (std::isnan(v)) {
    return "NaN";
  }
  if (std::isinf(v)) {
    return v > 0 ? "Infinity" : "-Infinity";
  }
  return v;
}

inline Json encode_scalar(const Json& typ, const Json& native) {
  const int code = type_code(typ);
  if (code == static_cast<int>(TypeCode::kBool)) {
    if (!native.is_boolean()) {
      throw MalformedWireError("BOOL native value is not boolean");
    }
    return native.get<bool>();
  }
  if (code == static_cast<int>(TypeCode::kInt64) || code == static_cast<int>(TypeCode::kEnum)) {
    if (native.is_string()) {
      return native.get<std::string>();
    }
    if (native.is_number_integer()) {
      return std::to_string(native.get<int64_t>());
    }
    throw MalformedWireError("INT64 native value is not string or integer");
  }
  if (code == static_cast<int>(TypeCode::kFloat32) || code == static_cast<int>(TypeCode::kFloat64)) {
    if (!native.is_number()) {
      throw MalformedWireError("FLOAT native value is not number");
    }
    return encode_float64(native.get<double>());
  }
  if (code == static_cast<int>(TypeCode::kBytes) || code == static_cast<int>(TypeCode::kProto)) {
    if (native.is_string()) {
      return native.get<std::string>();
    }
    if (native.is_array()) {
      std::vector<uint8_t> bytes;
      bytes.reserve(native.size());
      for (const Json& b : native) {
        bytes.push_back(static_cast<uint8_t>(b.get<int>()));
      }
      return encode_base64_wire(bytes);
    }
    throw MalformedWireError("BYTES native value is not string or byte array");
  }
  if (native.is_string()) {
    return native.get<std::string>();
  }
  throw MalformedWireError("string-compatible native value is not string");
}

inline Json encode_value(const Json& typ, const Json& native) {
  if (is_native_null(native)) {
    return nullptr;
  }

  const int code = type_code(typ);
  if (code == static_cast<int>(TypeCode::kArray)) {
    const Json elem_type = array_element_type(typ);
    if (!elem_type.is_object() || elem_type.empty()) {
      throw MalformedWireError("ARRAY missing array_element_type");
    }
    if (!native.is_array()) {
      throw MalformedWireError("ARRAY native value is not array");
    }
    Json out = Json::array();
    for (const Json& elem : native) {
      out.push_back(encode_value(elem_type, elem));
    }
    return out;
  }

  if (code == static_cast<int>(TypeCode::kStruct)) {
    const std::vector<Json> fields = struct_fields(typ);
    if (!native.is_array()) {
      throw MalformedWireError("STRUCT native value is not array");
    }
    if (native.size() != fields.size()) {
      throw MismatchedFieldsError("got " + std::to_string(native.size()) + " native field values, want " +
                                  std::to_string(fields.size()));
    }
    Json out = Json::array();
    for (std::size_t i = 0; i < fields.size(); ++i) {
      out.push_back(encode_value(field_type(fields[i]), native.at(i)));
    }
    return out;
  }

  return encode_scalar(typ, native);
}

inline std::vector<std::string> format_result_row(const std::vector<Json>& types,
                                                  const std::vector<Json>& native_values,
                                                  const FormatConfig& config) {
  if (types.size() != native_values.size()) {
    throw std::invalid_argument("len(types)=" + std::to_string(types.size()) + " != len(values)=" +
                                std::to_string(native_values.size()));
  }
  std::vector<Json> wire;
  wire.reserve(types.size());
  for (std::size_t i = 0; i < types.size(); ++i) {
    wire.push_back(encode_value(types[i], native_values[i]));
  }
  return format_row(types, wire, config);
}

inline Json wire_to_native(const Json& typ, const Json& wire) {
  if (is_null_value(wire)) {
    return nullptr;
  }

  const int code = type_code(typ);
  if (code == static_cast<int>(TypeCode::kArray)) {
    const Json elem_type = array_element_type(typ);
    Json out = Json::array();
    for (const Json& elem : list_values(wire)) {
      out.push_back(wire_to_native(elem_type, elem));
    }
    return out;
  }

  if (code == static_cast<int>(TypeCode::kStruct)) {
    const std::vector<Json> fields = struct_fields(typ);
    const std::vector<Json> vals = list_values(wire);
    if (vals.size() != fields.size()) {
      throw MismatchedFieldsError("got " + std::to_string(vals.size()) + " wire field values, want " +
                                  std::to_string(fields.size()));
    }
    Json out = Json::array();
    for (std::size_t i = 0; i < fields.size(); ++i) {
      out.push_back(wire_to_native(field_type(fields[i]), vals[i]));
    }
    return out;
  }

  if (code == static_cast<int>(TypeCode::kBool)) {
    return bool_value(wire);
  }
  if (code == static_cast<int>(TypeCode::kFloat32) || code == static_cast<int>(TypeCode::kFloat64)) {
    return gcv_float64(wire);
  }
  if (code == static_cast<int>(TypeCode::kInt64) || code == static_cast<int>(TypeCode::kEnum)) {
    const std::string s = string_value(wire);
    if (is_decimal_int_string(s)) {
      return std::stoll(s);
    }
    return s;
  }
  if (code == static_cast<int>(TypeCode::kBytes) || code == static_cast<int>(TypeCode::kProto)) {
    const std::vector<uint8_t> bytes = decode_base64_wire(string_value(wire));
    Json out = Json::array();
    for (uint8_t b : bytes) {
      out.push_back(static_cast<int>(b));
    }
    return out;
  }
  return string_value(wire);
}

inline bool wire_equal(const Json& a, const Json& b) {
  if (a.is_null() && b.is_null()) {
    return true;
  }
  if (a.is_number() && b.is_number()) {
    return a.get<double>() == b.get<double>();
  }
  if (a.type() != b.type()) {
    return false;
  }
  if (a.is_array()) {
    if (a.size() != b.size()) {
      return false;
    }
    for (std::size_t i = 0; i < a.size(); ++i) {
      if (!wire_equal(a.at(i), b.at(i))) {
        return false;
      }
    }
    return true;
  }
  return a == b;
}

}  // namespace spanvalue
