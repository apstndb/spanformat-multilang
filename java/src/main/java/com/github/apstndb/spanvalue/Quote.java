package com.github.apstndb.spanvalue;

/** GoogleSQL literal quoting and escaping. */
public final class Quote {
  private Quote() {}

  public enum QuoteStrategy {
    LEGACY,
    ALWAYS,
    MIN_ESCAPE
  }

  public enum PreferredQuote {
    DOUBLE,
    SINGLE
  }

  public record LiteralQuoteConfig(
      QuoteStrategy strategy, PreferredQuote preferredQuote) {

    public LiteralQuoteConfig() {
      this(QuoteStrategy.LEGACY, PreferredQuote.DOUBLE);
    }

    public static LiteralQuoteConfig normalize(LiteralQuoteConfig cfg) {
      QuoteStrategy strategy = cfg.strategy();
      if (strategy != QuoteStrategy.LEGACY
          && strategy != QuoteStrategy.ALWAYS
          && strategy != QuoteStrategy.MIN_ESCAPE) {
        strategy = QuoteStrategy.LEGACY;
      }
      PreferredQuote preferred = cfg.preferredQuote();
      if (preferred != PreferredQuote.DOUBLE && preferred != PreferredQuote.SINGLE) {
        preferred = PreferredQuote.DOUBLE;
      }
      return new LiteralQuoteConfig(strategy, preferred);
    }
  }

  public static char quoteCharForPayload(byte[] data, LiteralQuoteConfig cfg) {
    cfg = LiteralQuoteConfig.normalize(cfg);
    if (cfg.strategy() == QuoteStrategy.ALWAYS) {
      return cfg.preferredQuote() == PreferredQuote.SINGLE ? '\'' : '"';
    }

    int pref = cfg.preferredQuote() == PreferredQuote.SINGLE ? '\'' : '"';
    int other = pref == '\'' ? '"' : '\'';

    if (cfg.strategy() == QuoteStrategy.MIN_ESCAPE) {
      int singleCount = 0;
      int doubleCount = 0;
      for (byte b : data) {
        if (b == '\'') {
          singleCount++;
        } else if (b == '"') {
          doubleCount++;
        }
      }
      if (singleCount < doubleCount) {
        return '\'';
      }
      if (doubleCount < singleCount) {
        return '"';
      }
      return (char) pref;
    }

    boolean hasPref = false;
    for (byte b : data) {
      if (b == other) {
        return (char) pref;
      }
      if (b == pref) {
        hasPref = true;
      }
    }
    if (hasPref) {
      return (char) other;
    }
    return (char) pref;
  }

  public static char quoteCharForPayload(String payload, LiteralQuoteConfig cfg) {
    return quoteCharForPayload(payload.getBytes(java.nio.charset.StandardCharsets.UTF_8), cfg);
  }

  public static String escapeRune(int r, boolean isString, char quote) {
    if (r == '\\' || (quote != 0 && r == quote)) {
      return "\\" + (char) r;
    }
    if (isString && r == '\n') {
      return "\\n";
    }
    if (isString && r == '\r') {
      return "\\r";
    }
    if (isString && r == '\t') {
      return "\\t";
    }
    if (isString && isPrintable(r)) {
      return new String(Character.toChars(r));
    }
    if (0x20 <= r && r <= 0x7E) {
      return new String(Character.toChars(r));
    }
    if (r < 0x100) {
      return String.format("\\x%02x", r);
    }
    if (r > 0xFFFF) {
      return String.format("\\U%08x", r);
    }
    return String.format("\\u%04x", r);
  }

  private static boolean isPrintable(int r) {
    if (r == 0x20) {
      return true;
    }
    int type = Character.getType(r);
    return switch (type) {
      case Character.UPPERCASE_LETTER,
          Character.LOWERCASE_LETTER,
          Character.TITLECASE_LETTER,
          Character.MODIFIER_LETTER,
          Character.OTHER_LETTER,
          Character.NON_SPACING_MARK,
          Character.ENCLOSING_MARK,
          Character.COMBINING_SPACING_MARK,
          Character.DECIMAL_DIGIT_NUMBER,
          Character.LETTER_NUMBER,
          Character.OTHER_NUMBER,
          Character.CONNECTOR_PUNCTUATION,
          Character.DASH_PUNCTUATION,
          Character.START_PUNCTUATION,
          Character.END_PUNCTUATION,
          Character.INITIAL_QUOTE_PUNCTUATION,
          Character.FINAL_QUOTE_PUNCTUATION,
          Character.OTHER_PUNCTUATION,
          Character.MATH_SYMBOL,
          Character.CURRENCY_SYMBOL,
          Character.MODIFIER_SYMBOL,
          Character.OTHER_SYMBOL ->
          true;
      default -> false;
    };
  }

  public static String toStringLiteral(String s, LiteralQuoteConfig cfg) {
    char quote = quoteCharForPayload(s, cfg);
    StringBuilder parts = new StringBuilder();
    parts.append(quote);
    s.codePoints().forEach(cp -> parts.append(escapeRune(cp, true, quote)));
    parts.append(quote);
    return parts.toString();
  }

  public static String toBytesLiteral(byte[] data, LiteralQuoteConfig cfg) {
    char quote = quoteCharForPayload(data, cfg);
    StringBuilder parts = new StringBuilder();
    parts.append('b').append(quote);
    for (byte b : data) {
      parts.append(escapeRune(b & 0xFF, false, quote));
    }
    parts.append(quote);
    return parts.toString();
  }

  public static String sqlCastQuoted(String payload, String castType, LiteralQuoteConfig cfg) {
    return "CAST(" + toStringLiteral(payload, cfg) + " AS " + castType + ")";
  }
}
