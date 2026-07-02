# C++ Spanner emulator example

Runs `query_format.cpp` against the [Cloud Spanner emulator](https://cloud.google.com/spanner/docs/emulator) using [google-cloud-cpp](https://github.com/googleapis/google-cloud-cpp) (Spanner client) from [vcpkg](https://vcpkg.io/).

## Prerequisites

1. Emulator running on `localhost:9010` (see [`../README.md`](../README.md)).
2. One-time bootstrap: `SPANNER_EMULATOR_HOST=localhost:9010 ../setup-emulator.sh`
3. [vcpkg](https://vcpkg.io/en/getting-started) installed and bootstrapped (example below uses `~/vcpkg`).

Dependencies are declared in [`vcpkg.json`](vcpkg.json) (`google-cloud-cpp` with the `spanner` feature). The first CMake configure installs them into `build/vcpkg_installed/` (manifest mode).

### Install vcpkg (once)

```bash
git clone https://github.com/microsoft/vcpkg.git ~/vcpkg
~/vcpkg/bootstrap-vcpkg.sh
```

On Apple Silicon macOS you can pre-install the triplet (optional; CMake manifest install does the same):

```bash
~/vcpkg/vcpkg install 'google-cloud-cpp[spanner]:arm64-osx'
```

Use `x64-osx` on Intel Macs.

## Build and run

```bash
export VCPKG_ROOT="${VCPKG_ROOT:-$HOME/vcpkg}"
cd examples/cpp
cmake -B build \
  -DCMAKE_TOOLCHAIN_FILE="$VCPKG_ROOT/scripts/buildsystems/vcpkg.cmake" \
  -DVCPKG_TARGET_TRIPLET=arm64-osx
cmake --build build
SPANNER_EMULATOR_HOST=localhost:9010 ./build/query_format
```

Expected stdout:

```
encode_value (n): "1"
format_result_row: ["1","hello","true"]
```

## Notes

- Requires C++20 (`proto_adapt.hpp` uses concepts).
- The high-level `RowStream` API does not expose `ResultSetMetadata`; this demo uses `google::spanner::v1::Type` wire types matching the fixed `SELECT` metadata.
