# frozen_string_literal: true

require 'base64'

module Spanvalue
  module BytesFmt
    module_function

    def decode_base64_wire(wire)
      return ''.b if wire == ''

      Base64.strict_decode64(wire)
    end

    def readable_ascii?(data)
      data.each_byte do |c|
        return false if c == 92 || c < 0x20 || c > 0x7E
      end
      true
    end

    def readable_bytes_string(data)
      return '' if data.empty?
      return data.dup.force_encoding(Encoding::UTF_8) if readable_ascii?(data)

      data.each_byte.map { |b| Quote.escape_rune(b, false, '') }.join
    end

    def readable_string_from_base64_wire(wire)
      readable_bytes_string(decode_base64_wire(wire))
    end
  end
end
