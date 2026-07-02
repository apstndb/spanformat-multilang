# spanvalue (C++)

Format Cloud Spanner `google.spanner.v1.Type` values and column wire values
(`google.protobuf.Value`) as human-readable text, re-parseable GoogleSQL
literals, or byte-for-byte [spanner-cli](https://github.com/cloudspannerecosystem/spanner-cli)
compatible output.

Header-first library (CMake). C++17. Uses vendored [nlohmann/json](https://github.com/nlohmann/json).

## Input model

The public API accepts **`nlohmann::json` only** — protojson-shaped JSON objects
and arrays. There is no protobuf C++ dependency and no duck-typed template
adapters over `google::spanner::v1` protos.

Integrate by serializing protobuf messages to JSON (protojson) or building
`nlohmann::json` values manually. High-level Spanner client row types are not
accepted.

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

## License

MIT
