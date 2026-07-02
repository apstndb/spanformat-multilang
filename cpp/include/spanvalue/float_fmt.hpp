#pragma once

#include <cmath>
#include <cstdint>
#include <cstdio>
#include <cstring>
#include <limits>
#include <optional>
#include <regex>
#include <sstream>
#include <string>

#include "spanvalue/quote.hpp"

namespace spanvalue {

inline float narrow_float32(double v) {
  float f = static_cast<float>(v);
  return f;
}

inline bool float_bits_equal(float a, float b) {
  uint32_t ua = 0;
  uint32_t ub = 0;
  std::memcpy(&ua, &a, sizeof(float));
  std::memcpy(&ub, &b, sizeof(float));
  return ua == ub;
}

inline bool double_bits_equal(double a, double b) {
  uint64_t ua = 0;
  uint64_t ub = 0;
  std::memcpy(&ua, &a, sizeof(double));
  std::memcpy(&ub, &b, sizeof(double));
  return ua == ub;
}

inline bool round_trips(const std::string& s, double original, int bits) {
  char* end = nullptr;
  const double parsed = std::strtod(s.c_str(), &end);
  if (end == s.c_str() || *end != '\0') {
    return false;
  }
  if (std::isnan(original)) {
    return std::isnan(parsed);
  }
  if (std::isinf(original)) {
    return std::isinf(parsed) && ((parsed > 0) == (original > 0));
  }
  if (bits == 32) {
    return float_bits_equal(narrow_float32(parsed), narrow_float32(original));
  }
  return double_bits_equal(parsed, original);
}

inline std::string fmt_exponent(int exp) {
  if (exp >= 0) {
    char buf[16];
    std::snprintf(buf, sizeof(buf), "e+%02d", exp);
    return buf;
  }
  const int ae = std::abs(exp);
  if (ae < 10) {
    char buf[16];
    std::snprintf(buf, sizeof(buf), "e-%02d", ae);
    return buf;
  }
  char buf[16];
  std::snprintf(buf, sizeof(buf), "e%d", exp);
  return buf;
}

inline std::string pe_to_go_g(const std::string& es) {
  static const std::regex kERe(R"(^(\d)(?:\.(\d+))?e([+-])(\d+)$)");
  std::smatch m;
  if (!std::regex_match(es, m, kERe)) {
    throw std::runtime_error("unexpected e-format: " + es);
  }
  std::string d1 = m[1].str();
  std::string rest = m[2].matched ? m[2].str() : "";
  const char esign = m[3].str()[0];
  const int eexp = std::stoi(m[4].str());
  int exp = (esign == '+' ? 1 : -1) * eexp;

  while (!rest.empty() && rest.back() == '0') {
    rest.pop_back();
  }
  std::string sig = d1 + rest;
  if (sig.empty()) {
    sig = "0";
  }
  const int ndigits = static_cast<int>(sig.size());

  if (-4 <= exp && exp < 6) {
    const int dec_pos = 1 + exp;
    std::string s;
    if (dec_pos <= 0) {
      s = "0." + std::string(static_cast<std::size_t>(-dec_pos), '0') + sig;
    } else if (dec_pos >= ndigits) {
      s = sig + std::string(static_cast<std::size_t>(dec_pos - ndigits), '0');
    } else {
      s = sig.substr(0, static_cast<std::size_t>(dec_pos)) + "." +
          sig.substr(static_cast<std::size_t>(dec_pos));
    }
    if (s.find('.') != std::string::npos) {
      while (!s.empty() && s.back() == '0') {
        s.pop_back();
      }
      if (!s.empty() && s.back() == '.') {
        s.pop_back();
      }
    }
    return s;
  }

  std::string body;
  if (ndigits == 1) {
    body = sig;
  } else {
    body = sig.substr(0, 1) + "." + sig.substr(1);
  }
  return body + fmt_exponent(exp);
}

inline std::string format_go_g(double v, int bits = 64) {
  if (bits == 32) {
    v = narrow_float32(v);
  }

  if (std::isnan(v)) {
    return "NaN";
  }
  if (std::isinf(v)) {
    return v < 0 ? "-Inf" : "+Inf";
  }
  if (v == 0.0 && std::signbit(v)) {
    return "-0";
  }

  const bool negative = v < 0;
  const double av = negative ? -v : v;
  const int max_p = bits == 64 ? 16 : 8;
  const double target = bits == 64 ? v : narrow_float32(v);

  std::optional<std::string> best;
  for (int p = 0; p <= max_p; ++p) {
    char buf[64];
    std::snprintf(buf, sizeof(buf), "%.*e", p, av);
    const std::string g = pe_to_go_g(buf);
    std::string candidate = (negative ? "-" : "") + g;
    if (round_trips(candidate, target, bits)) {
      if (!best || candidate.size() < best->size()) {
        best = candidate;
      }
    }
  }

  if (!best) {
    std::ostringstream oss;
    oss << (negative ? "-" : "") << av;
    return oss.str();
  }
  return *best;
}

inline std::string format_spanner_cli_float(double v, int bits = 64) {
  if (bits == 32) {
    v = narrow_float32(v);
  }
  if (std::isnan(v)) {
    return "NaN";
  }
  if (std::isinf(v)) {
    return v < 0 ? "-Inf" : "+Inf";
  }
  const char* fmt = (v == std::trunc(v)) ? "%.0f" : "%.6f";
  int n = std::snprintf(nullptr, 0, fmt, v);
  if (n < 0) {
    return std::to_string(v);
  }
  std::string out(static_cast<std::size_t>(n), '\0');
  std::snprintf(out.data(), out.size() + 1, fmt, v);
  return out;
}

inline std::string float64_to_literal(double v, LiteralQuoteConfig quote_cfg) {
  if (std::isnan(v)) {
    return sql_cast_quoted("nan", "FLOAT64", quote_cfg);
  }
  if (std::isinf(v)) {
    return sql_cast_quoted(v < 0 ? "-inf" : "inf", "FLOAT64", quote_cfg);
  }
  std::string s = format_go_g(v, 64);
  if (s.find('.') == std::string::npos && s.find('e') == std::string::npos &&
      s.find('E') == std::string::npos) {
    s += ".0";
  }
  return s;
}

inline std::string float32_to_literal(double v, LiteralQuoteConfig quote_cfg) {
  const float fv = narrow_float32(v);
  if (std::isnan(fv)) {
    return sql_cast_quoted("nan", "FLOAT32", quote_cfg);
  }
  if (std::isinf(fv)) {
    return sql_cast_quoted(fv < 0 ? "-inf" : "inf", "FLOAT32", quote_cfg);
  }
  return "CAST(" + format_go_g(fv, 32) + " AS FLOAT32)";
}

}  // namespace spanvalue
