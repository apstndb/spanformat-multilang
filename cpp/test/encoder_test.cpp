#define CATCH_CONFIG_MAIN
#include <catch2/catch.hpp>

#include <fstream>
#include <string>

#include <nlohmann/json.hpp>

#include "spanvalue/encoder.hpp"

namespace {

using Json = nlohmann::json;

Json load_conformance(const std::string& path) {
  std::ifstream in(path);
  REQUIRE(in.is_open());
  Json data;
  in >> data;
  return data;
}

}  // namespace

#ifndef SPANVALUE_CONFORMANCE_PATH
#define SPANVALUE_CONFORMANCE_PATH "../testdata/conformance.json"
#endif

TEST_CASE("encoder round-trip value cases", "[encoder]") {
  const Json conformance = load_conformance(SPANVALUE_CONFORMANCE_PATH);
  const spanvalue::FormatConfig config = spanvalue::simple_format_config();

  for (const Json& case_obj : conformance.at("value_cases")) {
    const Json& typ = case_obj.at("type");
    const Json& wire = case_obj.at("value");
    const Json native = spanvalue::wire_to_native(typ, wire);
    const Json encoded = spanvalue::encode_value(typ, native);
    INFO("encoder case " << case_obj.at("name").get<std::string>());
    REQUIRE(spanvalue::wire_equal(encoded, wire));

    const std::string formatted = spanvalue::format_value(typ, encoded, config);
    const std::string want = case_obj.at("expected").at("simple");
    REQUIRE(formatted == want);
  }
}

TEST_CASE("format_result_row smoke", "[encoder]") {
  const Json bool_type = {{"code", "BOOL"}};
  const Json int_type = {{"code", "INT64"}};
  const Json str_type = {{"code", "STRING"}};
  const std::vector<Json> types = {bool_type, int_type, str_type};
  const std::vector<Json> natives = {true, nullptr, "ok"};
  const spanvalue::FormatConfig config = spanvalue::simple_format_config();

  const std::vector<std::string> got = spanvalue::format_result_row(types, natives, config);
  REQUIRE(got.size() == 3);
  REQUIRE(got[0] == "true");
  REQUIRE(got[1] == "<null>");
  REQUIRE(got[2] == "ok");
}

TEST_CASE("format_result_row struct and null", "[encoder]") {
  const Json struct_type = {
      {"code", "STRUCT"},
      {"structType",
       {{"fields",
         Json::array({{{"name", "n"}, {"type", {{"code", "INT64"}}}},
                      {{"name", "s"}, {"type", {{"code", "STRING"}}}}})}}}};
  const Json bool_type = {{"code", "BOOL"}};
  const std::vector<Json> types = {bool_type, struct_type};
  const std::vector<Json> natives = {nullptr, Json::array({42, "x"})};
  const spanvalue::FormatConfig config = spanvalue::simple_format_config();

  const std::vector<std::string> got = spanvalue::format_result_row(types, natives, config);
  REQUIRE(got.size() == 2);
  REQUIRE(got[0] == "<null>");
  REQUIRE(got[1] == "(42 AS n, x AS s)");
}
