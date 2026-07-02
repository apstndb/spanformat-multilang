#pragma once

#include <string>
#include <type_traits>
#include <utility>

#include <nlohmann/json.hpp>

#include "spanvalue/proto_json.hpp"

namespace spanvalue {

/// Optional duck-typing adapter when protobuf C++ types are available in the
/// caller's build. Serialize protobuf messages to protojson JSON for the
/// header-only encoder/formatters when protobuf is not linked.
template <typename T>
inline Json adapt_client_type(const T& typ) {
  if constexpr (requires(const T& t) { t.code(); }) {
    Json out = {{"code", typ.code()}};
    if constexpr (requires(const T& t) { t.type_annotation(); }) {
      out["typeAnnotation"] = typ.type_annotation();
    }
    if constexpr (requires(const T& t) { t.proto_type_fqn(); }) {
      const std::string fqn = typ.proto_type_fqn();
      if (!fqn.empty()) {
        out["protoTypeFqn"] = fqn;
      }
    }
    if constexpr (requires(const T& t) { t.array_element_type(); }) {
      if (typ.has_array_element_type()) {
        out["arrayElementType"] = adapt_client_type(typ.array_element_type());
      }
    }
    if constexpr (requires(const T& t) { t.struct_type(); }) {
      if (typ.has_struct_type()) {
        Json fields = Json::array();
        for (const auto& field : typ.struct_type().fields()) {
          fields.push_back({
              {"name", field.name()},
              {"type", adapt_client_type(field.type())},
          });
        }
        out["structType"] = Json{{"fields", std::move(fields)}};
      }
    }
    return out;
  } else {
    static_assert(sizeof(T) == 0, "adapt_client_type requires protobuf-shaped accessors");
    return Json::object();
  }
}

}  // namespace spanvalue
