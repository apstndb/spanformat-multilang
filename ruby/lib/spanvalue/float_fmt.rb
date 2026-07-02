# frozen_string_literal: true

require 'fiddle'

module Spanvalue
  module FloatFmt
    E_RE = /\A(\d)(?:\.(\d+))?e([+-])(\d+)\z/i

    module_function

    def libc_parsers
      @libc_parsers ||= begin
        libc = Fiddle.dlopen(nil)
        {
          strtod: Fiddle::Function.new(
            libc['strtod'],
            [Fiddle::TYPE_VOIDP, Fiddle::TYPE_VOIDP],
            Fiddle::TYPE_DOUBLE
          ),
          strtof: Fiddle::Function.new(
            libc['strtof'],
            [Fiddle::TYPE_VOIDP, Fiddle::TYPE_VOIDP],
            Fiddle::TYPE_FLOAT
          )
        }
      end
    end

    def parse_float_string(s, bits)
      parsers = libc_parsers
      buf = Fiddle::Pointer[s + "\0"]
      endp = Fiddle::Pointer.malloc(Fiddle::SIZEOF_VOIDP)
      if bits == 32
        parsers[:strtof].call(buf, endp).to_f
      else
        parsers[:strtod].call(buf, endp)
      end
    end

    def narrow_float32(v)
      [v].pack('e').unpack1('e')
    end

    def pack_float32(v)
      narrow_float32(v)
    end

    def round_trips?(s, original, bits)
      parsed = parse_float_string(s, bits)
      return original.nan? if parsed.nan?
      if parsed.infinite?
        return original.infinite? && (parsed <=> 0) == (original <=> 0)
      end

      if bits == 32
        return pack_float32(parsed) == pack_float32(original)
      end

      parsed == original
    rescue ArgumentError, TypeError
      false
    end

    def fmt_exponent(exp)
      if exp >= 0
        format('e+%02d', exp)
      elsif exp.abs < 10
        format('e-%02d', exp.abs)
      else
        "e#{exp}"
      end
    end

    def pe_to_go_g(es)
      m = E_RE.match(es)
      raise ArgumentError, "unexpected e-format: #{es.inspect}" unless m

      d1 = m[1]
      rest = m[2] || ''
      exp = Integer("#{m[3]}#{m[4]}")
      sig = (d1 + rest).sub(/0+\z/, '')
      sig = '0' if sig.empty?
      ndigits = sig.length

      if exp >= -4 && exp < 6
        dec_pos = 1 + exp
        s = if dec_pos <= 0
              "0.#{'0' * -dec_pos}#{sig}"
            elsif dec_pos >= ndigits
              "#{sig}#{'0' * (dec_pos - ndigits)}"
            else
              "#{sig[0...dec_pos]}.#{sig[dec_pos..]}"
            end
        if s.include?('.')
          s = s.sub(/0+\z/, '').sub(/\.\z/, '')
        end
        return s
      end

      body = ndigits == 1 ? sig : "#{sig[0]}.#{sig[1..]}"
      "#{body}#{fmt_exponent(exp)}"
    end

    def format_go_g(v, bits = 64)
      v = narrow_float32(v) if bits == 32

      return 'NaN' if v.nan?
      return '-Inf' if v.infinite? && v.negative?
      return '+Inf' if v.infinite?
      if v.zero? && (1.0 / v).infinite? && (1.0 / v).negative?
        return '-0'
      end

      negative = v.negative?
      av = v.abs
      max_p = bits == 64 ? 16 : 8
      target = bits == 64 ? v : narrow_float32(v)
      best = nil

      (0..max_p).each do |p|
        es = format("%.*e", p, av)
        g = pe_to_go_g(es)
        candidate = "#{negative ? '-' : ''}#{g}"
        next unless round_trips?(candidate, target, bits)

        best = candidate if best.nil? || candidate.length < best.length
      end

      best || "#{negative ? '-' : ''}#{av.inspect}"
    end

    def format_spanner_cli_float(v, bits = 64)
      v = narrow_float32(v) if bits == 32
      return 'NaN' if v.nan?
      return '-Inf' if v.infinite? && v.negative?
      return '+Inf' if v.infinite?

      if v == v.truncate
        format('%.0f', v)
      else
        format('%.6f', v)
      end
    end

    def float64_to_literal(v, quote_cfg)
      if v.nan?
        return Quote.sql_cast_quoted('nan', 'FLOAT64', quote_cfg)
      end
      if v.infinite?
        payload = v.negative? ? '-inf' : 'inf'
        return Quote.sql_cast_quoted(payload, 'FLOAT64', quote_cfg)
      end

      s = format_go_g(v, 64)
      s += '.0' unless s.match?(/[.eE]/)
      s
    end

    def float32_to_literal(v, quote_cfg)
      fv = narrow_float32(v)
      if fv.nan?
        return Quote.sql_cast_quoted('nan', 'FLOAT32', quote_cfg)
      end
      if fv.infinite?
        payload = fv.negative? ? '-inf' : 'inf'
        return Quote.sql_cast_quoted(payload, 'FLOAT32', quote_cfg)
      end

      "CAST(#{format_go_g(fv, 32)} AS FLOAT32)"
    end
  end
end
