#pragma once

#include <cstdint>
#include <string>
#include <vector>

#include "spanvalue/quote.hpp"

namespace spanvalue {

inline std::vector<uint8_t> decode_base64_wire(const std::string& wire) {
  if (wire.empty()) {
    return {};
  }
  static const int8_t kDecode[256] = {
      -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1,
      -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, -1, 62,
      -1, -1, -1, 63, 52, 53, 54, 55, 56, 57, 58, 59, 60, 61, -1, -1, -1, -1, -1, -1, -1, 0,
      1,  2,  3,  4,  5,  6,  7,  8,  9,  10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22,
      23, 24, 25, -1, -1, -1, -1, -1, -1, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38,
      39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51, -1, -1, -1, -1, -1,
  };

  std::vector<uint8_t> out;
  out.reserve(wire.size() * 3 / 4);
  int val = 0;
  int valb = -8;
  for (unsigned char c : wire) {
    if (c == '=') {
      break;
    }
    const int8_t d = kDecode[c];
    if (d < 0) {
      continue;
    }
    val = (val << 6) + d;
    valb += 6;
    if (valb >= 0) {
      out.push_back(static_cast<uint8_t>((val >> valb) & 0xFF));
      valb -= 8;
    }
  }
  return out;
}

inline bool is_readable_ascii(const std::vector<uint8_t>& data) {
  for (uint8_t c : data) {
    if (c == static_cast<uint8_t>('\\') || c < 0x20 || c > 0x7E) {
      return false;
    }
  }
  return true;
}

inline std::string readable_bytes_string(const std::vector<uint8_t>& data) {
  if (data.empty()) {
    return "";
  }
  if (is_readable_ascii(data)) {
    return std::string(reinterpret_cast<const char*>(data.data()), data.size());
  }
  std::string out;
  for (uint8_t b : data) {
    out += escape_rune(b, false, '\0');
  }
  return out;
}

inline std::string readable_string_from_base64_wire(const std::string& wire) {
  return readable_bytes_string(decode_base64_wire(wire));
}

inline std::string encode_base64_wire(const std::vector<uint8_t>& data) {
  static const char kEncode[] =
      "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
  if (data.empty()) {
    return "";
  }
  std::string out;
  out.reserve((data.size() + 2) / 3 * 4);
  std::size_t i = 0;
  while (i + 3 <= data.size()) {
    const uint32_t n = (static_cast<uint32_t>(data[i]) << 16) |
                       (static_cast<uint32_t>(data[i + 1]) << 8) |
                       static_cast<uint32_t>(data[i + 2]);
    out.push_back(kEncode[(n >> 18) & 63]);
    out.push_back(kEncode[(n >> 12) & 63]);
    out.push_back(kEncode[(n >> 6) & 63]);
    out.push_back(kEncode[n & 63]);
    i += 3;
  }
  const std::size_t rem = data.size() - i;
  if (rem == 1) {
    const uint32_t n = static_cast<uint32_t>(data[i]) << 16;
    out.push_back(kEncode[(n >> 18) & 63]);
    out.push_back(kEncode[(n >> 12) & 63]);
    out.push_back('=');
    out.push_back('=');
  } else if (rem == 2) {
    const uint32_t n = (static_cast<uint32_t>(data[i]) << 16) |
                       (static_cast<uint32_t>(data[i + 1]) << 8);
    out.push_back(kEncode[(n >> 18) & 63]);
    out.push_back(kEncode[(n >> 12) & 63]);
    out.push_back(kEncode[(n >> 6) & 63]);
    out.push_back('=');
  }
  return out;
}

}  // namespace spanvalue
