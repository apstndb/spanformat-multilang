#pragma once

#include <cstdint>
#include <optional>
#include <stdexcept>
#include <string>
#include <unordered_map>

namespace spanvalue {

enum class TypeCode : int {
  kUnspecified = 0,
  kBool = 1,
  kInt64 = 2,
  kFloat64 = 3,
  kFloat32 = 4,
  kTimestamp = 5,
  kDate = 6,
  kString = 7,
  kBytes = 8,
  kArray = 9,
  kStruct = 10,
  kNumeric = 11,
  kJson = 12,
  kProto = 13,
  kEnum = 14,
  kInterval = 15,
  kUuid = 16,
};

enum class TypeAnnotationCode : int {
  kUnspecified = 0,
  kPgNumeric = 2,
  kPgJsonb = 3,
  kPgOid = 4,
};

inline const std::unordered_map<int, const char*>& type_code_names() {
  static const std::unordered_map<int, const char*> kNames = {
      {0, "TYPE_CODE_UNSPECIFIED"}, {1, "BOOL"},           {2, "INT64"},
      {3, "FLOAT64"},               {4, "FLOAT32"},        {5, "TIMESTAMP"},
      {6, "DATE"},                  {7, "STRING"},         {8, "BYTES"},
      {9, "ARRAY"},                 {10, "STRUCT"},        {11, "NUMERIC"},
      {12, "JSON"},                 {13, "PROTO"},         {14, "ENUM"},
      {15, "INTERVAL"},             {16, "UUID"},
  };
  return kNames;
}

inline const std::unordered_map<int, const char*>& type_annotation_names() {
  static const std::unordered_map<int, const char*> kNames = {
      {0, "TYPE_ANNOTATION_CODE_UNSPECIFIED"},
      {2, "PG_NUMERIC"},
      {3, "PG_JSONB"},
      {4, "PG_OID"},
  };
  return kNames;
}

inline std::optional<std::string> type_code_name(int code) {
  const auto& names = type_code_names();
  auto it = names.find(code);
  if (it == names.end()) {
    return std::nullopt;
  }
  return std::string(it->second);
}

inline int parse_type_code_enum(const std::string& name) {
  static const std::unordered_map<std::string, int> kByName = {
      {"TYPE_CODE_UNSPECIFIED", 0}, {"BOOL", 1},       {"INT64", 2},
      {"FLOAT64", 3},               {"FLOAT32", 4},    {"TIMESTAMP", 5},
      {"DATE", 6},                  {"STRING", 7},     {"BYTES", 8},
      {"ARRAY", 9},                 {"STRUCT", 10},    {"NUMERIC", 11},
      {"JSON", 12},                 {"PROTO", 13},     {"ENUM", 14},
      {"INTERVAL", 15},             {"UUID", 16},
  };
  auto it = kByName.find(name);
  if (it == kByName.end()) {
    throw std::invalid_argument("unknown TypeCode name: " + name);
  }
  return it->second;
}

inline int parse_type_annotation_enum(const std::string& name) {
  static const std::unordered_map<std::string, int> kByName = {
      {"TYPE_ANNOTATION_CODE_UNSPECIFIED", 0},
      {"PG_NUMERIC", 2},
      {"PG_JSONB", 3},
      {"PG_OID", 4},
  };
  auto it = kByName.find(name);
  if (it == kByName.end()) {
    throw std::invalid_argument("unknown TypeAnnotationCode name: " + name);
  }
  return it->second;
}

inline bool is_decimal_int_string(const std::string& s) {
  if (s.empty()) {
    return false;
  }
  std::size_t i = 0;
  if (s[0] == '-') {
    if (s.size() == 1) {
      return false;
    }
    i = 1;
  }
  for (; i < s.size(); ++i) {
    if (s[i] < '0' || s[i] > '9') {
      return false;
    }
  }
  return true;
}

}  // namespace spanvalue
