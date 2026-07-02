# spanvalue (C++)

Format Cloud Spanner `google.spanner.v1.Type` values and column wire values
(`google.protobuf.Value`) as human-readable text, re-parseable GoogleSQL
literals, or byte-for-byte [spanner-cli](https://github.com/cloudspannerecosystem/spanner-cli)
compatible output.

Header-first library (CMake). C++17. Uses vendored [nlohmann/json](https://github.com/nlohmann/json).

## Input model

The public API accepts **`nlohmann::json` only** — protojson-shaped JSON objects
and arrays. There is no protobuf C++ dependency in the default build.

[`proto_adapt.hpp`](include/spanvalue/proto_adapt.hpp) provides an optional
C++20 `adapt_client_type` template when your build already links protobuf
message types with `code()` / `array_element_type()` accessors. Otherwise
serialize protos to protojson JSON.

High-level Spanner client row types are not accepted.

## Wire encoders

[`encoder.hpp`](include/spanvalue/encoder.hpp):

- `encode_value(type_json, native_json)` → wire `Value` JSON
- `format_result_row(types, native_values, config)` → encode + `format_row`

Native JSON uses protojson shorthand (`true`, `"1"`, `3.14`, arrays for
ARRAY/STRUCT).

## Quick start

```cpp
#include <spanvalue/format.hpp>
#include <nlohmann/json.hpp>

nlohmann::json typ = {
    {"code", "STRUCT"},
    {"structType", {
        {"fields", {
            {{"name", "n"}, {"type", {{"code", "INT64"}}}},
            {{"name", "s"}, {"type", {{"code", "STRING"}}}}
        }}
    }}
};
nlohmann::json value = nlohmann::json::array({"1", "hello"});

auto config = spanvalue::literal_format_config();
std::cout << spanvalue::format_value(typ, value, config) << '\n';
// STRUCT<n INT64, s STRING>(1, "hello")
```

## Tests

```bash
cd cpp && cmake -B build && cmake --build build && ctest --test-dir build
```

Conformance cases load `../testdata/conformance.json`.

## Integration example

See [`../examples/cpp/`](../examples/cpp/) for a runnable Spanner emulator demo using `adapt_client_type`, `encode_value`, and `format_result_row` with `google::cloud::spanner::Client`. Requires google-cloud-cpp. Setup: [`../examples/README.md`](../examples/README.md).

## License

MIT
