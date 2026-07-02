#define CATCH_CONFIG_MAIN
#include <catch2/catch.hpp>

#include <fstream>
#include <string>
#include <unordered_map>

#include <nlohmann/json.hpp>

#include "spanvalue/spanvalue.hpp"

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

TEST_CASE("type conformance cases", "[conformance][type]") {
  const Json conformance = load_conformance(SPANVALUE_CONFORMANCE_PATH);

  const std::unordered_map<std::string, spanvalue::FormatOption> presets = {
      {"simplest", spanvalue::format_option_simplest()},
      {"simple", spanvalue::format_option_simple()},
      {"normal", spanvalue::format_option_normal()},
      {"verbose", spanvalue::format_option_verbose()},
      {"more_verbose", spanvalue::format_option_more_verbose()},
  };

  for (const auto& [preset_name, option] : presets) {
    for (const Json& case_obj : conformance.at("type_cases")) {
      const std::string got = spanvalue::format_type(case_obj.at("type"), option);
      const std::string want = case_obj.at("expected").at(preset_name);
      INFO("type case " << case_obj.at("name").get<std::string>() << " preset " << preset_name);
      REQUIRE(got == want);
    }
  }

  for (const Json& case_obj : conformance.at("type_cases")) {
    const std::string got = spanvalue::format_type_verbose_annotation_omit(case_obj.at("type"));
    const std::string want = case_obj.at("expected").at("verbose_annotation_omit");
    INFO("type case " << case_obj.at("name").get<std::string>() << " preset verbose_annotation_omit");
    REQUIRE(got == want);
  }

  for (const Json& case_obj : conformance.at("type_cases")) {
    const std::string got = spanvalue::format_type_verbose_annotation_primary(case_obj.at("type"));
    const std::string want = case_obj.at("expected").at("verbose_annotation_primary");
    INFO("type case " << case_obj.at("name").get<std::string>() << " preset verbose_annotation_primary");
    REQUIRE(got == want);
  }
}

TEST_CASE("value conformance cases", "[conformance][value]") {
  const Json conformance = load_conformance(SPANVALUE_CONFORMANCE_PATH);

  const std::unordered_map<std::string, spanvalue::FormatConfig> configs = {
      {"simple", spanvalue::simple_format_config()},
      {"literal", spanvalue::literal_format_config()},
      {"spanner_cli", spanvalue::spanner_cli_format_config()},
  };

  for (const auto& [preset_name, config] : configs) {
    for (const Json& case_obj : conformance.at("value_cases")) {
      const std::string got =
          spanvalue::format_value(case_obj.at("type"), case_obj.at("value"), config);
      const std::string want = case_obj.at("expected").at(preset_name);
      INFO("value case " << case_obj.at("name").get<std::string>() << " preset " << preset_name);
      REQUIRE(got == want);
    }
  }
}

TEST_CASE("value literal quote policies", "[conformance][quotes]") {
  const Json conformance = load_conformance(SPANVALUE_CONFORMANCE_PATH);

  const std::unordered_map<std::string, spanvalue::LiteralQuoteConfig> policies = {
      {"legacy_double",
       spanvalue::LiteralQuoteConfig{spanvalue::QuoteStrategy::kLegacy,
                                     spanvalue::PreferredQuote::kDouble}},
      {"legacy_single",
       spanvalue::LiteralQuoteConfig{spanvalue::QuoteStrategy::kLegacy,
                                     spanvalue::PreferredQuote::kSingle}},
      {"always_double",
       spanvalue::LiteralQuoteConfig{spanvalue::QuoteStrategy::kAlways,
                                     spanvalue::PreferredQuote::kDouble}},
      {"always_single",
       spanvalue::LiteralQuoteConfig{spanvalue::QuoteStrategy::kAlways,
                                     spanvalue::PreferredQuote::kSingle}},
      {"min_escape_double",
       spanvalue::LiteralQuoteConfig{spanvalue::QuoteStrategy::kMinEscape,
                                     spanvalue::PreferredQuote::kDouble}},
      {"min_escape_single",
       spanvalue::LiteralQuoteConfig{spanvalue::QuoteStrategy::kMinEscape,
                                     spanvalue::PreferredQuote::kSingle}},
  };

  for (const auto& [policy_name, quote] : policies) {
    const spanvalue::FormatConfig config = spanvalue::literal_format_config(quote);
    for (const Json& case_obj : conformance.at("value_cases")) {
      const std::string got =
          spanvalue::format_value(case_obj.at("type"), case_obj.at("value"), config);
      const std::string want = case_obj.at("expected").at("literal_quotes").at(policy_name);
      INFO("value case " << case_obj.at("name").get<std::string>() << " quote " << policy_name);
      REQUIRE(got == want);
    }
  }
}
