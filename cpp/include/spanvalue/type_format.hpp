#pragma once

#include <string>
#include <vector>

#include "spanvalue/codes.hpp"
#include "spanvalue/errors.hpp"
#include "spanvalue/proto_json.hpp"

namespace spanvalue {

enum class StructMode { kBase = 0, kRecursive = 1, kRecursiveWithName = 2 };

enum class ProtoEnumMode { kBase = 0, kLeaf = 1, kFull = 2, kLeafWithKind = 3, kFullWithKind = 4 };

enum class ArrayMode { kBase = 0, kRecursive = 1 };

enum class UnknownMode { kUnknown = 0, kTypeCode = 1, kVerbose = 2, kPanic = 3 };

enum class TypeAnnotationMode { kSuffix = 0, kOmit = 1, kPrimary = 2 };

struct FormatOption {
  StructMode struct_mode = StructMode::kBase;
  ProtoEnumMode proto = ProtoEnumMode::kBase;
  ProtoEnumMode enum_mode = ProtoEnumMode::kBase;
  ArrayMode array = ArrayMode::kBase;
  UnknownMode unknown = UnknownMode::kUnknown;
  TypeAnnotationMode type_annotation = TypeAnnotationMode::kSuffix;
};

inline const FormatOption& format_option_simplest() {
  static const FormatOption kOpt{StructMode::kBase,    ProtoEnumMode::kBase, ProtoEnumMode::kBase,
                                 ArrayMode::kBase,     UnknownMode::kTypeCode};
  return kOpt;
}

inline const FormatOption& format_option_simple() {
  static const FormatOption kOpt{StructMode::kBase,     ProtoEnumMode::kLeaf, ProtoEnumMode::kLeaf,
                                 ArrayMode::kRecursive, UnknownMode::kUnknown};
  return kOpt;
}

inline const FormatOption& format_option_normal() {
  static const FormatOption kOpt{StructMode::kRecursive, ProtoEnumMode::kLeaf, ProtoEnumMode::kLeaf,
                                 ArrayMode::kRecursive,  UnknownMode::kVerbose};
  return kOpt;
}

inline const FormatOption& format_option_verbose() {
  static const FormatOption kOpt{StructMode::kRecursiveWithName, ProtoEnumMode::kFull,
                                 ProtoEnumMode::kFull,           ArrayMode::kRecursive,
                                 UnknownMode::kVerbose};
  return kOpt;
}

inline const FormatOption& format_option_more_verbose() {
  static const FormatOption kOpt{StructMode::kRecursiveWithName, ProtoEnumMode::kFullWithKind,
                                 ProtoEnumMode::kFullWithKind,   ArrayMode::kRecursive,
                                 UnknownMode::kVerbose};
  return kOpt;
}

inline std::string last_cut(const std::string& s, char sep) {
  const auto pos = s.rfind(sep);
  if (pos == std::string::npos) {
    return s;
  }
  return s.substr(pos + 1);
}

inline std::string annotation_suffix(int ann) {
  if (ann == static_cast<int>(TypeAnnotationCode::kUnspecified)) {
    return "";
  }
  const auto& names = type_annotation_names();
  auto it = names.find(ann);
  if (it == names.end()) {
    return "(" + std::to_string(ann) + ")";
  }
  return std::string("(") + it->second + ")";
}

inline std::string annotation_name(int ann) {
  const auto& names = type_annotation_names();
  auto it = names.find(ann);
  if (it == names.end()) {
    return std::to_string(ann);
  }
  return it->second;
}

inline std::string format_type_code(int code, UnknownMode mode = UnknownMode::kVerbose) {
  const auto name = type_code_name(code);
  if (name) {
    return *name;
  }
  if (mode == UnknownMode::kTypeCode) {
    return std::to_string(code);
  }
  if (mode == UnknownMode::kVerbose) {
    return "UNKNOWN(" + std::to_string(code) + ")";
  }
  if (mode == UnknownMode::kPanic) {
    throw UnknownTypeError("unknown TypeCode(" + std::to_string(code) + ")");
  }
  return "UNKNOWN";
}

inline std::string format_proto_enum(const Json& typ, ProtoEnumMode mode) {
  const int code = type_code(typ);
  const std::string fqn = proto_type_fqn(typ);
  const std::string code_name = format_type_code(code);
  if (mode == ProtoEnumMode::kLeaf) {
    return last_cut(fqn, '.');
  }
  if (mode == ProtoEnumMode::kFull) {
    return fqn;
  }
  if (mode == ProtoEnumMode::kLeafWithKind) {
    return code_name + "<" + last_cut(fqn, '.') + ">";
  }
  if (mode == ProtoEnumMode::kFullWithKind) {
    return code_name + "<" + fqn + ">";
  }
  return code_name;
}

inline std::string format_type(const Json& typ, const FormatOption& option);
inline std::string format_type_impl(const Json& typ, const FormatOption& option);

inline std::string format_struct_fields(const std::vector<Json>& fields,
                                        const FormatOption& option) {
  std::vector<std::string> parts;
  parts.reserve(fields.size());
  for (const Json& field : fields) {
    std::string type_str = format_type(field_type(field), option);
    if (option.struct_mode == StructMode::kRecursiveWithName) {
      const std::string name = field_name(field);
      if (!name.empty()) {
        parts.push_back(name + " " + type_str);
        continue;
      }
    }
    parts.push_back(std::move(type_str));
  }
  std::string out;
  for (std::size_t i = 0; i < parts.size(); ++i) {
    if (i > 0) {
      out += ", ";
    }
    out += parts[i];
  }
  return out;
}

inline std::string format_type_impl(const Json& typ, const FormatOption& option) {
  const int code = type_code(typ);
  if (code == static_cast<int>(TypeCode::kArray) && option.array != ArrayMode::kBase) {
    return "ARRAY<" + format_type(array_element_type(typ), option) + ">";
  }
  if (code == static_cast<int>(TypeCode::kProto)) {
    return format_proto_enum(typ, option.proto);
  }
  if (code == static_cast<int>(TypeCode::kEnum)) {
    return format_proto_enum(typ, option.enum_mode);
  }
  if (code == static_cast<int>(TypeCode::kStruct) && option.struct_mode != StructMode::kBase) {
    return "STRUCT<" + format_struct_fields(struct_fields(typ), option) + ">";
  }
  return format_type_code(code, option.unknown);
}

inline std::string format_type(const Json& typ, const FormatOption& option) {
  const int ann = type_annotation(typ);
  if (option.type_annotation == TypeAnnotationMode::kOmit) {
    return format_type_impl(typ, option);
  }
  if (option.type_annotation == TypeAnnotationMode::kPrimary) {
    if (ann != static_cast<int>(TypeAnnotationCode::kUnspecified)) {
      return annotation_name(ann);
    }
    return format_type_impl(typ, option);
  }
  return format_type_impl(typ, option) + annotation_suffix(ann);
}

inline std::string format_type(const Json& typ) { return format_type(typ, format_option_simple()); }

inline std::string format_type_simplest(const Json& typ) { return format_type(typ, format_option_simplest()); }
inline std::string format_type_simple(const Json& typ) { return format_type(typ, format_option_simple()); }
inline std::string format_type_normal(const Json& typ) { return format_type(typ, format_option_normal()); }
inline std::string format_type_verbose(const Json& typ) { return format_type(typ, format_option_verbose()); }
inline std::string format_type_more_verbose(const Json& typ) {
  return format_type(typ, format_option_more_verbose());
}

inline std::string format_type_verbose_annotation_omit(const Json& typ) {
  FormatOption opt = format_option_verbose();
  opt.type_annotation = TypeAnnotationMode::kOmit;
  return format_type(typ, opt);
}

inline std::string format_type_verbose_annotation_primary(const Json& typ) {
  FormatOption opt = format_option_verbose();
  opt.type_annotation = TypeAnnotationMode::kPrimary;
  return format_type(typ, opt);
}

}  // namespace spanvalue
