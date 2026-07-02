# frozen_string_literal: true

require 'base64'
require 'json'
require 'minitest/autorun'
require 'pathname'

require_relative '../lib/spanvalue'

class EncoderTest < Minitest::Test
  CONFORMANCE_PATH = Pathname.new(__dir__).join('../../testdata/conformance.json').expand_path

  def self.conformance
    @conformance ||= JSON.parse(File.read(CONFORMANCE_PATH, encoding: 'UTF-8'))
  end

  def conformance
    self.class.conformance
  end

  def normalize_wire(value)
    return nil if value.nil? || Spanvalue::Proto.null_value?(value)

    case Spanvalue::Proto.value_kind(value)
    when 'bool' then Spanvalue::Proto.bool_value(value)
    when 'number' then Spanvalue::Proto.number_value(value)
    when 'string' then Spanvalue::Proto.string_value(value)
    when 'list' then Spanvalue::Proto.list_values(value).map { |elem| normalize_wire(elem) }
    else value
    end
  end

  def assert_wire_equal(expected, actual)
    exp = normalize_wire(expected)
    act = normalize_wire(actual)
    if exp.nil?
      assert_nil act
    else
      assert_equal exp, act
    end
  end

  def wire_to_native(typ, value)
    return nil if value.nil? || Spanvalue::Proto.null_value?(value)

    code = Spanvalue::Proto.type_code(typ)
    case code
    when Spanvalue::TypeCode::BOOL
      Spanvalue::Proto.bool_value(value)
    when Spanvalue::TypeCode::INT64, Spanvalue::TypeCode::ENUM
      Spanvalue::Proto.string_value(value)
    when Spanvalue::TypeCode::FLOAT32, Spanvalue::TypeCode::FLOAT64
      if Spanvalue::Proto.value_kind(value) == 'string'
        s = Spanvalue::Proto.string_value(value)
        return Float::NAN if s == 'NaN'
        return Float::INFINITY if s == 'Infinity'
        return -Float::INFINITY if s == '-Infinity'
      end
      Spanvalue::Proto.number_value(value)
    when Spanvalue::TypeCode::BYTES, Spanvalue::TypeCode::PROTO
      Base64.decode64(Spanvalue::Proto.string_value(value)).force_encoding(Encoding::ASCII_8BIT)
    when Spanvalue::TypeCode::STRING, Spanvalue::TypeCode::TIMESTAMP, Spanvalue::TypeCode::DATE, Spanvalue::TypeCode::NUMERIC,
         Spanvalue::TypeCode::INTERVAL, Spanvalue::TypeCode::UUID, Spanvalue::TypeCode::JSON
      Spanvalue::Proto.string_value(value)
    when Spanvalue::TypeCode::ARRAY
      elem_type = Spanvalue::Proto.array_element_type(typ)
      Spanvalue::Proto.list_values(value).map { |elem| wire_to_native(elem_type, elem) }
    when Spanvalue::TypeCode::STRUCT
      fields = Spanvalue::Proto.struct_fields(typ)
      Spanvalue::Proto.list_values(value).each_with_index.map do |elem, i|
        wire_to_native(Spanvalue::Proto.field_type(fields[i]), elem)
      end
    else
      value
    end
  end

  def test_encode_value_round_trip_conformance
    conformance.fetch('value_cases').each do |test_case|
      native = wire_to_native(test_case['type'], test_case['value'])
      encoded = Spanvalue.encode_value(test_case['type'], native)
      assert_wire_equal(test_case['value'], encoded)
      got = Spanvalue.format_value(test_case['type'], encoded, Spanvalue.simple_format_config)
      assert_equal test_case.dig('expected', 'simple'), got, "format after encode for #{test_case['name']}"
    end
  end

  def test_encode_struct_hash
    typ = {
      code: 'STRUCT',
      structType: {
        fields: [
          { name: 'n', type: { code: 'INT64' } },
          { name: 's', type: { code: 'STRING' } }
        ]
      }
    }
    encoded = Spanvalue.encode_value(typ, { 'n' => '1', 's' => 'foo' })
    assert_wire_equal(%w[1 foo], encoded)
    got = Spanvalue.format_value(typ, encoded, Spanvalue.literal_format_config)
    assert_equal 'STRUCT<n INT64, s STRING>(1, "foo")', got
  end

  def test_adapt_client_type
    client = {
      code: 'ARRAY',
      arrayElementType: { code: 'INT64' }
    }
    assert_equal(
      { code: 'ARRAY', arrayElementType: { code: 'INT64' } },
      Spanvalue::ClientTypeAdapter.adapt(client)
    )
  end

  def test_format_result_row
    types = [
      { code: 'INT64' },
      { code: 'STRING' },
      {
        code: 'STRUCT',
        structType: {
          fields: [
            { name: 'n', type: { code: 'INT64' } },
            { name: 's', type: { code: 'STRING' } }
          ]
        }
      }
    ]
    values = [42, nil, { 'n' => '7', 's' => 'x' }]
    got = Spanvalue.format_result_row(types, values, Spanvalue.simple_format_config)
    assert_equal ['42', '<null>', '(7 AS n, x AS s)'], got
  end
end
