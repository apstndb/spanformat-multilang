# frozen_string_literal: true

require 'base64'
require 'json'

module Spanvalue
  module Encoder
    module_function

    def encode_value(typ, native_value, index: nil, field_name: nil)
      return nil if native_null?(native_value)

      code = Proto.type_code(typ)
      label = Codes.type_code_name(code) || code.to_s

      encode_scalar_or_complex(typ, native_value, code, label, index: index, field_name: field_name)
    rescue MismatchedFieldsError, UnknownTypeError
      raise
    rescue TypeError => e
      raise wrap_encode_error(e, index: index, field_name: field_name)
    end

    def format_result_row(types, values, config)
      raise ArgumentError, "len(types)=#{types.length} != len(values)=#{values.length}" if types.length != values.length

      wire_values = types.each_with_index.map { |typ, i| encode_value(typ, values[i]) }
      ValueFormat.format_row(types, wire_values, config)
    end

    def native_null?(value)
      value.nil?
    end
    private_class_method :native_null?

    def encode_scalar_or_complex(typ, native_value, code, label, index:, field_name:)
      case code
      when TypeCode::BOOL
        raise TypeError, "BOOL native value must be true or false: #{native_value.class}" unless native_value.is_a?(TrueClass) || native_value.is_a?(FalseClass)

        native_value
      when TypeCode::INT64, TypeCode::ENUM
        encode_int64(native_value)
      when TypeCode::FLOAT32, TypeCode::FLOAT64
        encode_float(native_value)
      when TypeCode::STRING, TypeCode::TIMESTAMP, TypeCode::DATE, TypeCode::NUMERIC, TypeCode::INTERVAL, TypeCode::UUID
        raise TypeError, "#{label} native value must be a String: #{native_value.class}" unless native_value.is_a?(String)

        native_value
      when TypeCode::JSON
        encode_json(native_value)
      when TypeCode::BYTES, TypeCode::PROTO
        encode_bytes(native_value)
      when TypeCode::ARRAY
        encode_array(typ, native_value)
      when TypeCode::STRUCT
        encode_struct(typ, native_value)
      else
        raise UnknownTypeError, "cannot encode type code #{label}"
      end
    end
    private_class_method :encode_scalar_or_complex

    def encode_int64(value)
      case value
      when Integer
        value.to_s
      when String
        raise TypeError, "INT64 native value must be a decimal integer string: #{value.inspect}" unless value.match?(/\A-?\d+\z/)

        value
      else
        raise TypeError, "INT64 native value must be Integer or decimal String: #{value.class}"
      end
    end
    private_class_method :encode_int64

    def encode_float(value)
      case value
      when String
        return value if %w[NaN Infinity -Infinity].include?(value)

        value = Float(value)
      when Numeric
        value = value.to_f
      else
        raise TypeError, "FLOAT native value must be Numeric or special string: #{value.class}"
      end

      return 'NaN' if value.nan?
      return 'Infinity' if value == Float::INFINITY
      return '-Infinity' if value == -Float::INFINITY

      value
    end
    private_class_method :encode_float

    def encode_bytes(value)
      case value
      when String
        return Base64.strict_encode64(value) if value.encoding == Encoding::ASCII_8BIT

        value
      else
        raise TypeError, "BYTES/PROTO native value must be a String: #{value.class}"
      end
    end
    private_class_method :encode_bytes

    def encode_json(value)
      case value
      when String
        JSON.parse(value)
        value
      else
        JSON.generate(value)
      end
    end
    private_class_method :encode_json

    def encode_array(typ, native_value)
      raise TypeError, 'ARRAY native value must be an Array' unless native_value.is_a?(Array)

      elem_type = Proto.array_element_type(typ)
      raise UnknownTypeError, 'ARRAY type missing array_element_type' if elem_type.nil?

      native_value.each_with_index.map do |elem, i|
        encode_value(elem_type, elem, index: i)
      end
    end
    private_class_method :encode_array

    def encode_struct(typ, native_value)
      fields = Proto.struct_fields(typ)
      field_values =
        if native_value.is_a?(Array)
          native_value
        elsif native_value.is_a?(Hash)
          fields.map do |field|
            name = Proto.field_name(field)
            if !name.empty? && native_value.key?(name)
              native_value[name]
            elsif !name.empty? && native_value.key?(name.to_sym)
              native_value[name.to_sym]
            else
              nil
            end
          end
        else
          raise TypeError, 'STRUCT native value must be an Array or Hash'
        end

      raise MismatchedFieldsError, "got #{field_values.length} values, want #{fields.length}" if field_values.length != fields.length

      fields.each_with_index.map do |field, i|
        encode_value(
          Proto.field_type(field),
          field_values[i],
          index: i,
          field_name: Proto.field_name(field)
        )
      end
    end
    private_class_method :encode_struct

    def wrap_encode_error(err, index:, field_name:)
      if !field_name.to_s.empty?
        TypeError.new("struct field #{index} (#{field_name.inspect}): #{err.message}")
      elsif !index.nil?
        TypeError.new("array element #{index}: #{err.message}")
      else
        err
      end
    end
    private_class_method :wrap_encode_error
  end
end
