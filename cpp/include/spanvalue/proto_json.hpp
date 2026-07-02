#pragma once

#include <cmath>
#include <cstdint>
#include <optional>
#include <string>
#include <vector>

#include <nlohmann/json.hpp>

#include "spanvalue/codes.hpp"

namespace spanvalue {

using Json = nlohmann::json;

inline const Json* json_get_ptr(const Json& obj, std::initializer_list<const char*> keys) {
  for (const char* key : keys) {
    if (obj.contains(key)) {
      return &obj.at(key);
    }
  }
  return nullptr;
}

inline int parse_type_code(const Json& value) {
  if (value.is_null()) {
    return static_cast<int>(TypeCode::kUnspecified);
  }
  if (value.is_number_integer()) {
    return value.get<int>();
  }
  if (value.is_string()) {
    const std::string s = value.get<std::string>();
    if (is_decimal_int_string(s)) {
      return std::stoi(s);
    }
    return parse_type_code_enum(s);
  }
  throw std::invalid_argument("cannot parse type code");
}

inline int parse_type_annotation(const Json& value) {
  if (value.is_null()) {
    return static_cast<int>(TypeAnnotationCode::kUnspecified);
  }
  if (value.is_number_integer()) {
    return value.get<int>();
  }
  if (value.is_string()) {
    const std::string s = value.get<std::string>();
    if (is_decimal_int_string(s)) {
      return std::stoi(s);
    }
    return parse_type_annotation_enum(s);
  }
  throw std::invalid_argument("cannot parse type annotation");
}

inline int type_code(const Json& typ) {
  const Json* code = json_get_ptr(typ, {"code", "Code"});
  if (code == nullptr) {
    return static_cast<int>(TypeCode::kUnspecified);
  }
  return parse_type_code(*code);
}

inline int type_annotation(const Json& typ) {
  const Json* ann =
      json_get_ptr(typ, {"type_annotation", "typeAnnotation", "TypeAnnotation"});
  if (ann == nullptr) {
    return static_cast<int>(TypeAnnotationCode::kUnspecified);
  }
  return parse_type_annotation(*ann);
}

inline std::string proto_type_fqn(const Json& typ) {
  const Json* fqn =
      json_get_ptr(typ, {"proto_type_fqn", "protoTypeFqn", "ProtoTypeFqn"});
  if (fqn == nullptr || fqn->is_null()) {
    return "";
  }
  return fqn->get<std::string>();
}

inline Json array_element_type(const Json& typ) {
  const Json* elem =
      json_get_ptr(typ, {"array_element_type", "arrayElementType", "ArrayElementType"});
  if (elem == nullptr) {
    return Json::object();
  }
  return *elem;
}

inline Json struct_type(const Json& typ) {
  const Json* st = json_get_ptr(typ, {"struct_type", "structType", "StructType"});
  if (st == nullptr) {
    return Json::object();
  }
  return *st;
}

inline std::vector<Json> struct_fields(const Json& typ) {
  const Json st = struct_type(typ);
  const Json* fields = json_get_ptr(st, {"fields", "Fields"});
  if (fields == nullptr || !fields->is_array()) {
    return {};
  }
  return fields->get<std::vector<Json>>();
}

inline std::string field_name(const Json& field) {
  const Json* name = json_get_ptr(field, {"name", "Name"});
  if (name == nullptr || name->is_null()) {
    return "";
  }
  return name->get<std::string>();
}

inline Json field_type(const Json& field) {
  const Json* t = json_get_ptr(field, {"type", "Type"});
  if (t == nullptr) {
    return Json::object();
  }
  return *t;
}

enum class ValueKind { kNull, kBool, kNumber, kString, kList, kMissing };

inline ValueKind value_kind(const Json& value) {
  if (value.is_null()) {
    return ValueKind::kNull;
  }
  if (value.is_boolean()) {
    return ValueKind::kBool;
  }
  if (value.is_number()) {
    return ValueKind::kNumber;
  }
  if (value.is_string()) {
    return ValueKind::kString;
  }
  if (value.is_array()) {
    return ValueKind::kList;
  }
  if (value.is_object()) {
    if (json_get_ptr(value, {"null_value", "nullValue"}) != nullptr) {
      return ValueKind::kNull;
    }
    if (json_get_ptr(value, {"bool_value", "boolValue"}) != nullptr) {
      return ValueKind::kBool;
    }
    if (json_get_ptr(value, {"number_value", "numberValue"}) != nullptr) {
      return ValueKind::kNumber;
    }
    if (json_get_ptr(value, {"string_value", "stringValue"}) != nullptr) {
      return ValueKind::kString;
    }
    if (json_get_ptr(value, {"list_value", "listValue"}) != nullptr) {
      return ValueKind::kList;
    }
    return ValueKind::kMissing;
  }
  return ValueKind::kMissing;
}

inline bool is_null_value(const Json& value) {
  const ValueKind kind = value_kind(value);
  return kind == ValueKind::kNull || kind == ValueKind::kMissing;
}

inline bool bool_value(const Json& value) {
  if (value.is_boolean()) {
    return value.get<bool>();
  }
  const Json* v = json_get_ptr(value, {"bool_value", "boolValue"});
  return v != nullptr && v->get<bool>();
}

inline double number_value(const Json& value) {
  if (value.is_number()) {
    return value.get<double>();
  }
  const Json* v = json_get_ptr(value, {"number_value", "numberValue"});
  return v->get<double>();
}

inline std::string string_value(const Json& value) {
  if (value.is_string()) {
    return value.get<std::string>();
  }
  const Json* v = json_get_ptr(value, {"string_value", "stringValue"});
  if (v == nullptr) {
    return "";
  }
  return v->get<std::string>();
}

inline std::vector<Json> list_values(const Json& value) {
  if (value.is_array()) {
    return value.get<std::vector<Json>>();
  }
  const Json* lv = json_get_ptr(value, {"list_value", "listValue"});
  if (lv == nullptr) {
    return {};
  }
  const Json* vals = json_get_ptr(*lv, {"values", "Values"});
  if (vals == nullptr || !vals->is_array()) {
    return {};
  }
  return vals->get<std::vector<Json>>();
}

inline std::string value_kind_name(ValueKind kind) {
  switch (kind) {
    case ValueKind::kNull:
      return "null";
    case ValueKind::kBool:
      return "bool";
    case ValueKind::kNumber:
      return "number";
    case ValueKind::kString:
      return "string";
    case ValueKind::kList:
      return "list";
    case ValueKind::kMissing:
      return "missing";
  }
  return "missing";
}

}  // namespace spanvalue
