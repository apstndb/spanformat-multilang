// Run a literal SELECT on the Spanner emulator and format cells with spanvalue.
//
// The high-level C++ RowStream does not expose ResultSetMetadata directly.
// This example builds wire types from google::spanner::v1::Type protos matching
// the metadata row_type returned for the query (INT64, STRING, BOOL).

#include <cstdlib>
#include <iostream>
#include <string>
#include <tuple>
#include <vector>

#include "google/cloud/spanner/client.h"
#include "google/cloud/spanner/row.h"
#include "google/cloud/spanner/sql_statement.h"
#include "google/spanner/v1/type.pb.h"
#include <nlohmann/json.hpp>
#include <spanvalue/encoder.hpp>
#include <spanvalue/format.hpp>
#include <spanvalue/proto_adapt.hpp>

namespace spanner = ::google::cloud::spanner;

namespace {

std::string env_or(const char* key, const char* default_value) {
  if (const char* value = std::getenv(key); value != nullptr && *value != '\0') {
    return value;
  }
  return default_value;
}

google::spanner::v1::Type make_wire_type(google::spanner::v1::TypeCode code) {
  google::spanner::v1::Type typ;
  typ.set_code(code);
  return typ;
}

nlohmann::json native_json(std::int64_t v) { return v; }
nlohmann::json native_json(std::string const& v) { return v; }
nlohmann::json native_json(bool v) { return v; }

}  // namespace

int main() {
  if (std::getenv("SPANNER_EMULATOR_HOST") == nullptr) {
    setenv("SPANNER_EMULATOR_HOST", "localhost:9010", 0);
  }

  const std::string project_id = env_or("SPANNER_PROJECT_ID", "test-project");
  const std::string instance_id = env_or("SPANNER_INSTANCE_ID", "test-instance");
  const std::string database_id = env_or("SPANNER_DATABASE_ID", "test-db");
  const std::string database_name = "projects/" + project_id + "/instances/" +
                                    instance_id + "/databases/" + database_id;

  spanner::Client client(spanner::MakeConnection(spanner::Database(database_name)));

  spanner::SqlStatement sql("SELECT 1 AS n, 'hello' AS s, true AS b");
  auto rows = client.ExecuteQuery(sql);
  auto config = spanvalue::simple_format_config();

  // Wire types equivalent to ExecuteSql metadata.row_type.fields[].type.
  const std::vector<google::spanner::v1::Type> metadata_types = {
      make_wire_type(google::spanner::v1::TypeCode::INT64),
      make_wire_type(google::spanner::v1::TypeCode::STRING),
      make_wire_type(google::spanner::v1::TypeCode::BOOL),
  };
  std::vector<nlohmann::json> col_types;
  col_types.reserve(metadata_types.size());
  for (auto const& wire_type : metadata_types) {
    col_types.push_back(spanvalue::adapt_client_type(wire_type));
  }

  using RowTuple = std::tuple<std::int64_t, std::string, bool>;
  bool printed = false;
  for (auto const& row_status : spanner::StreamOf<RowTuple>(rows)) {
    if (!row_status) {
      std::cerr << "Query failed: " << row_status.status() << '\n';
      return 1;
    }

    auto const& [n, s, b] = *row_status;
    std::vector<nlohmann::json> native_values = {
        native_json(n),
        native_json(s),
        native_json(b),
    };

    const auto encoded = spanvalue::encode_value(col_types[0], native_values[0]);
    std::cout << "encode_value (n): " << encoded.dump() << '\n';

    const auto formatted =
        spanvalue::format_result_row(col_types, native_values, config);
    std::cout << "format_result_row: " << nlohmann::json(formatted).dump() << '\n';
    printed = true;
    break;
  }

  if (!printed) {
    std::cerr << "Query returned no rows.\n";
    return 1;
  }
  return 0;
}
