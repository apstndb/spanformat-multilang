# frozen_string_literal: true

module Spanvalue
  module TypeCode
    TYPE_CODE_UNSPECIFIED = 0
    BOOL = 1
    INT64 = 2
    FLOAT64 = 3
    FLOAT32 = 4
    TIMESTAMP = 5
    DATE = 6
    STRING = 7
    BYTES = 8
    ARRAY = 9
    STRUCT = 10
    NUMERIC = 11
    JSON = 12
    PROTO = 13
    ENUM = 14
    INTERVAL = 15
    UUID = 16

    NAMES = {
      TYPE_CODE_UNSPECIFIED => 'TYPE_CODE_UNSPECIFIED',
      BOOL => 'BOOL',
      INT64 => 'INT64',
      FLOAT64 => 'FLOAT64',
      FLOAT32 => 'FLOAT32',
      TIMESTAMP => 'TIMESTAMP',
      DATE => 'DATE',
      STRING => 'STRING',
      BYTES => 'BYTES',
      ARRAY => 'ARRAY',
      STRUCT => 'STRUCT',
      NUMERIC => 'NUMERIC',
      JSON => 'JSON',
      PROTO => 'PROTO',
      ENUM => 'ENUM',
      INTERVAL => 'INTERVAL',
      UUID => 'UUID'
    }.freeze

    NAME_TO_VALUE = NAMES.each_with_object({}) { |(k, v), h| h[v] = k }.freeze
  end

  module TypeAnnotationCode
    TYPE_ANNOTATION_CODE_UNSPECIFIED = 0
    PG_NUMERIC = 2
    PG_JSONB = 3
    PG_OID = 4

    NAMES = {
      TYPE_ANNOTATION_CODE_UNSPECIFIED => 'TYPE_ANNOTATION_CODE_UNSPECIFIED',
      PG_NUMERIC => 'PG_NUMERIC',
      PG_JSONB => 'PG_JSONB',
      PG_OID => 'PG_OID'
    }.freeze

    NAME_TO_VALUE = NAMES.each_with_object({}) { |(k, v), h| h[v] = k }.freeze
  end

  module Codes
    module_function

    def parse_type_code(value)
      return TypeCode::TYPE_CODE_UNSPECIFIED if value.nil?

      case value
      when Integer
        value
      when String
        if value.match?(/\A-?\d+\z/)
          Integer(value, 10)
        else
          TypeCode::NAME_TO_VALUE.fetch(value)
        end
      else
        if value.respond_to?(:name)
          parse_type_code(value.name)
        elsif value.respond_to?(:value)
          parse_type_code(value.value)
        else
          raise TypeError, "cannot parse type code from #{value.inspect}"
        end
      end
    end

    def parse_type_annotation(value)
      return TypeAnnotationCode::TYPE_ANNOTATION_CODE_UNSPECIFIED if value.nil?

      case value
      when Integer
        value
      when String
        if value.match?(/\A-?\d+\z/)
          Integer(value, 10)
        else
          TypeAnnotationCode::NAME_TO_VALUE.fetch(value)
        end
      else
        if value.respond_to?(:name)
          parse_type_annotation(value.name)
        elsif value.respond_to?(:value)
          parse_type_annotation(value.value)
        else
          raise TypeError, "cannot parse type annotation from #{value.inspect}"
        end
      end
    end

    def type_code_name(code)
      TypeCode::NAMES[code]
    end
  end
end
