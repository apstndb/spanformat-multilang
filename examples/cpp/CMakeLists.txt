cmake_minimum_required(VERSION 3.16)
project(spanvalue_spanner_example CXX)

set(CMAKE_CXX_STANDARD 20)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

# spanvalue library (skip its CTest targets in this example build).
set(BUILD_TESTING OFF CACHE BOOL "" FORCE)
add_subdirectory(../../cpp ${CMAKE_BINARY_DIR}/spanvalue EXCLUDE_FROM_ALL)

find_package(google_cloud_cpp_spanner CONFIG REQUIRED)

add_executable(query_format query_format.cpp)
target_link_libraries(query_format PRIVATE google-cloud-cpp::spanner spanvalue)
