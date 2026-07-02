# frozen_string_literal: true

module Spanvalue
  module QuoteStrategy
    LEGACY = 0
    ALWAYS = 1
    MIN_ESCAPE = 2
  end

  module PreferredQuote
    DOUBLE = 0
    SINGLE = 1
  end

  LiteralQuoteConfig = Data.define(:strategy, :preferred_quote) do
    def initialize(strategy: QuoteStrategy::LEGACY, preferred_quote: PreferredQuote::DOUBLE)
      super
    end
  end

  module Quote
    module_function

    def normalize_literal_quote(cfg)
      strategy = cfg.strategy
      unless [QuoteStrategy::LEGACY, QuoteStrategy::ALWAYS, QuoteStrategy::MIN_ESCAPE].include?(strategy)
        strategy = QuoteStrategy::LEGACY
      end
      preferred = cfg.preferred_quote
      unless [PreferredQuote::DOUBLE, PreferredQuote::SINGLE].include?(preferred)
        preferred = PreferredQuote::DOUBLE
      end
      LiteralQuoteConfig.new(strategy: strategy, preferred_quote: preferred)
    end

    def quote_char_for_payload(payload, cfg)
      cfg = normalize_literal_quote(cfg)
      data = payload.is_a?(String) ? payload.b : payload

      if cfg.strategy == QuoteStrategy::ALWAYS
        return cfg.preferred_quote == PreferredQuote::SINGLE ? "'" : '"'
      end

      pref = cfg.preferred_quote == PreferredQuote::SINGLE ? 39 : 34
      other = pref == 39 ? 34 : 39

      if cfg.strategy == QuoteStrategy::MIN_ESCAPE
        single_count = data.count(39.chr)
        double_count = data.count(34.chr)
        return "'" if single_count < double_count
        return '"' if double_count < single_count

        return pref.chr
      end

      has_pref = false
      data.each_byte do |b|
        return pref.chr if b == other
        has_pref = true if b == pref
      end
      has_pref ? other.chr : pref.chr
    end

    def printable_rune?(r)
      return true if r == 0x20

      ch = [r].pack('U*')
      !!(ch =~ /\A[\p{L}\p{M}\p{N}\p{P}\p{S}]\z/u)
    end

    def codepoint_to_str(r)
      [r].pack('U*')
    end

    def escape_rune(r, is_string, quote)
      q = quote.empty? ? -1 : quote.ord
      if r == 92 || (!quote.empty? && r == q)
        return "\\#{codepoint_to_str(r)}"
      end
      return '\\n' if is_string && r == 10
      return '\\r' if is_string && r == 13
      return '\\t' if is_string && r == 9
      return codepoint_to_str(r) if is_string && printable_rune?(r)
      return codepoint_to_str(r) if r >= 0x20 && r <= 0x7E

      return format('\\x%02x', r) if r < 0x100
      return format('\\U%08x', r) if r > 0xFFFF

      format('\\u%04x', r)
    end

    def to_string_literal(s, cfg)
      quote = quote_char_for_payload(s, cfg)
      parts = [quote]
      s.each_codepoint { |cp| parts << escape_rune(cp, true, quote) }
      parts << quote
      parts.join
    end

    def to_bytes_literal(data, cfg)
      quote = quote_char_for_payload(data, cfg)
      parts = ['b', quote]
      data.each_byte { |b| parts << escape_rune(b, false, quote) }
      parts << quote
      parts.join
    end

    def sql_cast_quoted(payload, cast_type, cfg)
      lit = to_string_literal(payload, cfg)
      "CAST(#{lit} AS #{cast_type})"
    end
  end
end
