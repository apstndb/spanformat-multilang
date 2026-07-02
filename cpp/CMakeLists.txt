cmake_minimum_required(VERSION 3.16)
project(spanvalue VERSION 0.1.0 LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_CXX_EXTENSIONS OFF)

add_library(spanvalue INTERFACE)
target_include_directories(spanvalue INTERFACE
  $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/include>
  $<BUILD_INTERFACE:${CMAKE_CURRENT_SOURCE_DIR}/vendor>
  $<INSTALL_INTERFACE:include>
)

enable_testing()

set(SPANVALUE_CONFORMANCE_JSON "${CMAKE_CURRENT_SOURCE_DIR}/../testdata/conformance.json")

add_executable(spanvalue_conformance_test test/conformance_test.cpp)
target_link_libraries(spanvalue_conformance_test PRIVATE spanvalue)
target_compile_definitions(spanvalue_conformance_test PRIVATE
  SPANVALUE_CONFORMANCE_PATH="${SPANVALUE_CONFORMANCE_JSON}"
)

include(CTest)
add_test(NAME conformance COMMAND spanvalue_conformance_test)
