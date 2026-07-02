#pragma once

#include <cstdint>
#include <cstdio>
#include <string>
#include <string_view>
#include <vector>

#include "spanvalue/unicode_printable.hpp"

namespace spanvalue {

enum class QuoteStrategy { kLegacy = 0, kAlways = 1, kMinEscape = 2 };

enum class PreferredQuote { kDouble = 0, kSingle = 1 };

struct LiteralQuoteConfig {
  QuoteStrategy strategy = QuoteStrategy::kLegacy;
  PreferredQuote preferred_quote = PreferredQuote::kDouble;
};

inline LiteralQuoteConfig normalize_literal_quote(LiteralQuoteConfig cfg) {
  if (cfg.strategy != QuoteStrategy::kLegacy &&
      cfg.strategy != QuoteStrategy::kAlways &&
      cfg.strategy != QuoteStrategy::kMinEscape) {
    cfg.strategy = QuoteStrategy::kLegacy;
  }
  if (cfg.preferred_quote != PreferredQuote::kDouble &&
      cfg.preferred_quote != PreferredQuote::kSingle) {
    cfg.preferred_quote = PreferredQuote::kDouble;
  }
  return cfg;
}

inline char quote_char_for_payload(std::string_view payload, LiteralQuoteConfig cfg) {
  cfg = normalize_literal_quote(cfg);
  if (cfg.strategy == QuoteStrategy::kAlways) {
    return cfg.preferred_quote == PreferredQuote::kSingle ? '\'' : '"';
  }

  const char pref = cfg.preferred_quote == PreferredQuote::kSingle ? '\'' : '"';
  const char other = pref == '\'' ? '"' : '\'';

  if (cfg.strategy == QuoteStrategy::kMinEscape) {
    int single_count = 0;
    int double_count = 0;
    for (unsigned char b : payload) {
      if (b == '\'') {
        ++single_count;
      } else if (b == '"') {
        ++double_count;
      }
    }
    if (single_count < double_count) {
      return '\'';
    }
    if (double_count < single_count) {
      return '"';
    }
    return pref;
  }

  bool has_pref = false;
  for (unsigned char b : payload) {
    if (b == static_cast<unsigned char>(other)) {
      return pref;
    }
    if (b == static_cast<unsigned char>(pref)) {
      has_pref = true;
    }
  }
  if (has_pref) {
    return other;
  }
  return pref;
}

inline std::string utf8_encode(uint32_t cp) {
  std::string out;
  if (cp <= 0x7F) {
    out.push_back(static_cast<char>(cp));
  } else if (cp <= 0x7FF) {
    out.push_back(static_cast<char>(0xC0 | ((cp >> 6) & 0x1F)));
    out.push_back(static_cast<char>(0x80 | (cp & 0x3F)));
  } else if (cp <= 0xFFFF) {
    out.push_back(static_cast<char>(0xE0 | ((cp >> 12) & 0x0F)));
    out.push_back(static_cast<char>(0x80 | ((cp >> 6) & 0x3F)));
    out.push_back(static_cast<char>(0x80 | (cp & 0x3F)));
  } else {
    out.push_back(static_cast<char>(0xF0 | ((cp >> 18) & 0x07)));
    out.push_back(static_cast<char>(0x80 | ((cp >> 12) & 0x3F)));
    out.push_back(static_cast<char>(0x80 | ((cp >> 6) & 0x3F)));
    out.push_back(static_cast<char>(0x80 | (cp & 0x3F)));
  }
  return out;
}

inline std::string escape_rune(uint32_t r, bool is_string, char quote) {
  const int q = quote ? static_cast<unsigned char>(quote) : -1;
  if (r == static_cast<uint32_t>('\\') || (quote && r == static_cast<uint32_t>(q))) {
    return std::string("\\") + static_cast<char>(r);
  }
  if (is_string && r == '\n') {
    return "\\n";
  }
  if (is_string && r == '\r') {
    return "\\r";
  }
  if (is_string && r == '\t') {
    return "\\t";
  }
  if (is_string && detail::is_printable_rune(r)) {
    return utf8_encode(r);
  }
  if (r >= 0x20 && r <= 0x7E) {
    return std::string(1, static_cast<char>(r));
  }
  if (r < 0x100) {
    char buf[8];
    std::snprintf(buf, sizeof(buf), "\\x%02x", r);
    return buf;
  }
  if (r > 0xFFFF) {
    char buf[16];
    std::snprintf(buf, sizeof(buf), "\\U%08x", r);
    return buf;
  }
  char buf[12];
  std::snprintf(buf, sizeof(buf), "\\u%04x", r);
  return buf;
}

inline bool utf8_decode_one(std::string_view s, std::size_t& i, uint32_t& cp) {
  if (i >= s.size()) {
    return false;
  }
  const unsigned char c0 = static_cast<unsigned char>(s[i]);
  if (c0 < 0x80) {
    cp = c0;
    ++i;
    return true;
  }
  if ((c0 & 0xE0) == 0xC0 && i + 1 < s.size()) {
    cp = ((c0 & 0x1F) << 6) | (static_cast<unsigned char>(s[i + 1]) & 0x3F);
    i += 2;
    return true;
  }
  if ((c0 & 0xF0) == 0xE0 && i + 2 < s.size()) {
    cp = ((c0 & 0x0F) << 12) |
         ((static_cast<unsigned char>(s[i + 1]) & 0x3F) << 6) |
         (static_cast<unsigned char>(s[i + 2]) & 0x3F);
    i += 3;
    return true;
  }
  if ((c0 & 0xF8) == 0xF0 && i + 3 < s.size()) {
    cp = ((c0 & 0x07) << 18) |
         ((static_cast<unsigned char>(s[i + 1]) & 0x3F) << 12) |
         ((static_cast<unsigned char>(s[i + 2]) & 0x3F) << 6) |
         (static_cast<unsigned char>(s[i + 3]) & 0x3F);
    i += 4;
    return true;
  }
  cp = c0;
  ++i;
  return true;
}

inline std::string to_string_literal(const std::string& s, LiteralQuoteConfig cfg) {
  const char quote = quote_char_for_payload(s, cfg);
  std::string out;
  out.push_back(quote);
  for (std::size_t i = 0; i < s.size();) {
    uint32_t cp = 0;
    utf8_decode_one(s, i, cp);
    out += escape_rune(cp, true, quote);
  }
  out.push_back(quote);
  return out;
}

inline std::string to_bytes_literal(const std::vector<uint8_t>& data, LiteralQuoteConfig cfg) {
  std::string payload(reinterpret_cast<const char*>(data.data()), data.size());
  const char quote = quote_char_for_payload(payload, cfg);
  std::string out = "b";
  out.push_back(quote);
  for (uint8_t b : data) {
    out += escape_rune(b, false, quote);
  }
  out.push_back(quote);
  return out;
}

inline std::string sql_cast_quoted(const std::string& payload, const std::string& cast_type,
                                   LiteralQuoteConfig cfg) {
  return "CAST(" + to_string_literal(payload, cfg) + " AS " + cast_type + ")";
}

}  // namespace spanvalue
