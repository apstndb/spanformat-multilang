cmake_minimum_required(VERSION 3.16)
project(spanvalue_spanner_example CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

add_subdirectory(../../cpp ${CMAKE_BINARY_DIR}/spanvalue EXCLUDE_FROM_ALL)

find_package(google_cloud_cpp_spanner REQUIRED)

add_executable(query_format query_format.cpp)
target_link_libraries(query_format PRIVATE google-cloud-cpp::spanner spanvalue)
