# frozen_string_literal: true

module Spanvalue
  module Preset
    SIMPLE = 0
    LITERAL = 1
    SPANNER_CLI = 2
  end

  FormatConfig = Data.define(:preset, :null_string, :quote) do
    def initialize(preset: Preset::SIMPLE, null_string: '<null>', quote: LiteralQuoteConfig.new)
      super
      raise EmptyNullStringError, 'null_string must not be empty' if null_string.empty?
    end

    def with_null_string(null_string)
      with(null_string: null_string)
    end
  end

  module FormatConfigFactory
    module_function

    def simple_format_config(null_string = '<null>')
      FormatConfig.new(preset: Preset::SIMPLE, null_string: null_string)
    end

    def literal_format_config(quote = nil, null_string: 'NULL')
      q = Quote.normalize_literal_quote(quote || LiteralQuoteConfig.new)
      FormatConfig.new(preset: Preset::LITERAL, null_string: null_string, quote: q)
    end

    def spanner_cli_format_config(null_string = 'NULL')
      FormatConfig.new(preset: Preset::SPANNER_CLI, null_string: null_string)
    end
  end

  module ValueFormat
    SCALAR_TYPES = [
      TypeCode::BOOL,
      TypeCode::INT64,
      TypeCode::ENUM,
      TypeCode::FLOAT32,
      TypeCode::FLOAT64,
      TypeCode::STRING,
      TypeCode::BYTES,
      TypeCode::PROTO,
      TypeCode::TIMESTAMP,
      TypeCode::DATE,
      TypeCode::NUMERIC,
      TypeCode::JSON,
      TypeCode::INTERVAL,
      TypeCode::UUID
    ].freeze

    module_function

    def complex_type?(code)
      [TypeCode::ARRAY, TypeCode::STRUCT].include?(code)
    end

    def scalar_type?(code)
      SCALAR_TYPES.include?(code)
    end

    def require_string_wire!(value, code)
      return if Proto.value_kind(value) == 'string'

      raise MalformedWireError, "#{TypeFormat.format_type_code(code)} value kind #{Proto.value_kind(value).inspect}"
    end

    def require_bool_wire!(value, code)
      return if Proto.value_kind(value) == 'bool'

      raise MalformedWireError, "#{TypeFormat.format_type_code(code)} value kind #{Proto.value_kind(value).inspect}"
    end

    def validate_float_wire!(value, code)
      kind = Proto.value_kind(value)
      return if kind == 'number'
      if kind == 'string'
        s = Proto.string_value(value)
        return if %w[NaN Infinity -Infinity].include?(s)

        raise MalformedWireError, "#{TypeFormat.format_type_code(code)} unexpected float string #{s.inspect}"
      end

      raise MalformedWireError, "#{TypeFormat.format_type_code(code)} value kind #{kind.inspect}"
    end

    def gcv_float64(value)
      kind = Proto.value_kind(value)
      if kind == 'number'
        return Proto.number_value(value)
      end
      if kind == 'string'
        s = Proto.string_value(value)
        return Float::NAN if s == 'NaN'
        return Float::INFINITY if s == 'Infinity'
        return -Float::INFINITY if s == '-Infinity'

        raise MalformedWireError, "FLOAT64 unexpected float string #{s.inspect}"
      end

      raise MalformedWireError, "FLOAT64 value kind #{kind.inspect}"
    end

    def gcv_float32(value)
      FloatFmt.narrow_float32(gcv_float64(value))
    end

    def validate_scalar_wire!(typ, value)
      raise MalformedWireError, "nil type with value kind #{Proto.value_kind(value).inspect}" if typ.nil?
      raise MalformedWireError, "#{TypeFormat.format_type_code(Proto.type_code(typ))} unexpected null value" if Proto.null_value?(value)

      code = Proto.type_code(typ)
      case code
      when TypeCode::BOOL
        require_bool_wire!(value, code)
      when TypeCode::INT64, TypeCode::ENUM, TypeCode::STRING, TypeCode::BYTES, TypeCode::PROTO,
           TypeCode::TIMESTAMP, TypeCode::DATE, TypeCode::NUMERIC, TypeCode::INTERVAL,
           TypeCode::UUID, TypeCode::JSON
        require_string_wire!(value, code)
      when TypeCode::FLOAT32, TypeCode::FLOAT64
        validate_float_wire!(value, code)
      when TypeCode::TYPE_CODE_UNSPECIFIED
        raise UnknownTypeError, typ.inspect
      else
        raise UnknownTypeError, typ.inspect unless scalar_type?(code)
      end
    end

    def trim_spanner_cli_numeric_fraction(s)
      return s unless s.include?('.')

      s = s.sub(/0+\z/, '')
      s.sub(/\.\z/, '')
    end

    def numeric_wire_string(value)
      Proto.string_value(value)
    end

    def string_based_literal(type_name, payload, quote)
      "#{type_name} #{Quote.to_string_literal(payload, quote)}"
    end

    def format_scalar_simple(typ, value)
      validate_scalar_wire!(typ, value)
      code = Proto.type_code(typ)

      case code
      when TypeCode::BOOL
        Proto.bool_value(value) ? 'true' : 'false'
      when TypeCode::INT64, TypeCode::ENUM, TypeCode::STRING, TypeCode::TIMESTAMP,
           TypeCode::DATE, TypeCode::JSON, TypeCode::INTERVAL, TypeCode::UUID
        Proto.string_value(value)
      when TypeCode::FLOAT32
        FloatFmt.format_go_g(gcv_float32(value), 32)
      when TypeCode::FLOAT64
        FloatFmt.format_go_g(gcv_float64(value), 64)
      when TypeCode::BYTES, TypeCode::PROTO
        BytesFmt.readable_string_from_base64_wire(Proto.string_value(value))
      when TypeCode::NUMERIC
        numeric_wire_string(value)
      else
        raise UnknownTypeError, typ.inspect
      end
    end

    def format_scalar_literal(typ, value, quote)
      validate_scalar_wire!(typ, value)
      code = Proto.type_code(typ)

      case code
      when TypeCode::BOOL
        Proto.bool_value(value) ? 'true' : 'false'
      when TypeCode::INT64
        s = Proto.string_value(value)
        begin
          n = Integer(s, 10)
        rescue ArgumentError
          raise MalformedWireError, "invalid INT64 wire #{s.inspect}"
        end
        raise MalformedWireError, "INT64 out of range #{s.inspect}" if n < -(2**63) || n > (2**63) - 1

        s
      when TypeCode::FLOAT32
        FloatFmt.float32_to_literal(gcv_float32(value), quote)
      when TypeCode::FLOAT64
        FloatFmt.float64_to_literal(gcv_float64(value), quote)
      when TypeCode::STRING
        Quote.to_string_literal(Proto.string_value(value), quote)
      when TypeCode::BYTES, TypeCode::PROTO
        data = BytesFmt.decode_base64_wire(Proto.string_value(value))
        Quote.to_bytes_literal(data, quote)
      when TypeCode::TIMESTAMP
        string_based_literal('TIMESTAMP', Proto.string_value(value), quote)
      when TypeCode::DATE
        string_based_literal('DATE', Proto.string_value(value), quote)
      when TypeCode::NUMERIC
        string_based_literal('NUMERIC', numeric_wire_string(value), quote)
      when TypeCode::JSON
        string_based_literal('JSON', Proto.string_value(value), quote)
      when TypeCode::INTERVAL
        Quote.sql_cast_quoted(Proto.string_value(value), 'INTERVAL', quote)
      when TypeCode::UUID
        Quote.sql_cast_quoted(Proto.string_value(value), 'UUID', quote)
      else
        raise UnknownTypeError, typ.inspect
      end
    end

    def format_scalar_spanner_cli(typ, value)
      validate_scalar_wire!(typ, value)
      code = Proto.type_code(typ)

      case code
      when TypeCode::BOOL
        Proto.bool_value(value) ? 'true' : 'false'
      when TypeCode::INT64, TypeCode::ENUM, TypeCode::STRING, TypeCode::BYTES, TypeCode::PROTO,
           TypeCode::TIMESTAMP, TypeCode::DATE, TypeCode::INTERVAL, TypeCode::UUID, TypeCode::JSON
        Proto.string_value(value)
      when TypeCode::FLOAT32
        FloatFmt.format_spanner_cli_float(gcv_float32(value), 32)
      when TypeCode::FLOAT64
        FloatFmt.format_spanner_cli_float(gcv_float64(value), 64)
      when TypeCode::NUMERIC
        trim_spanner_cli_numeric_fraction(numeric_wire_string(value))
      else
        raise UnknownTypeError, typ.inspect
      end
    end

    def format_proto_literal(typ, value, quote, null_string)
      raise UnknownTypeError, typ.inspect unless Proto.type_code(typ) == TypeCode::PROTO
      return null_string if Proto.null_value?(value)

      require_string_wire!(value, TypeCode::PROTO)
      data = BytesFmt.decode_base64_wire(Proto.string_value(value))
      fqn = Proto.proto_type_fqn(typ)
      raise EmptyTypeFQNError, 'empty type FQN for PROTO' if fqn.empty?

      "CAST(#{Quote.to_bytes_literal(data, quote)} AS `#{fqn}`)"
    end

    def format_enum_literal(typ, value, null_string)
      raise UnknownTypeError, typ.inspect unless Proto.type_code(typ) == TypeCode::ENUM
      return null_string if Proto.null_value?(value)

      require_string_wire!(value, TypeCode::ENUM)
      s = Proto.string_value(value)
      begin
        Integer(s, 10)
      rescue ArgumentError
        raise MalformedWireError, "failed to parse enum wire payload #{s.inspect}"
      end
      fqn = Proto.proto_type_fqn(typ)
      raise EmptyTypeFQNError, 'empty type FQN for ENUM' if fqn.empty?

      "CAST(#{s} AS `#{fqn}`)"
    end

    def format_enum_simple(typ, value, null_string)
      return null_string if Proto.null_value?(value)

      format_scalar_simple(typ, value)
    end

    def get_list_value!(typ, value, expected_code)
      unless Proto.value_kind(value) == 'list'
        raise UnexpectedComplexValueKindError,
              "unexpected complex value kind for #{TypeFormat.format_type_code(expected_code)}: #{Proto.value_kind(value).inspect}"
      end

      Proto.list_values(value)
    end

    def format_value(typ, value, config, toplevel: true)
      return config.null_string if Proto.null_value?(value)

      code = Proto.type_code(typ)

      if code == TypeCode::ARRAY
        elems = get_list_value!(typ, value, code)
        elem_type = Proto.array_element_type(typ)
        parts = elems.map { |elem| format_value(elem_type, elem, config, toplevel: false) }
        joined = parts.join(', ')
        if config.preset == Preset::LITERAL && toplevel && complex_type?(Proto.type_code(elem_type))
          return "#{TypeFormat.format_type_verbose(typ)}[#{joined}]"
        end
        return "[#{joined}]"
      end

      if code == TypeCode::STRUCT
        field_vals = get_list_value!(typ, value, code)
        fields = Proto.struct_fields(typ)
        if field_vals.length != fields.length
          raise MismatchedFieldsError, "got #{field_vals.length} values, want #{fields.length}"
        end

        if config.preset == Preset::SIMPLE
          field_strs = fields.zip(field_vals).map do |field, val|
            rendered = format_value(Proto.field_type(field), val, config, toplevel: false)
            name = Proto.field_name(field)
            name.empty? ? rendered : "#{rendered} AS #{name}"
          end
          return "(#{field_strs.join(', ')})"
        end

        field_strs = fields.zip(field_vals).map do |field, val|
          format_value(Proto.field_type(field), val, config, toplevel: false)
        end
        inner = field_strs.join(', ')
        case config.preset
        when Preset::LITERAL
          prefix = toplevel ? TypeFormat.format_type_verbose(typ) : ''
          return "#{prefix}(#{inner})"
        when Preset::SPANNER_CLI
          return "[#{inner}]"
        else
          return "(#{inner})"
        end
      end

      if code == TypeCode::PROTO
        if config.preset == Preset::LITERAL
          return format_proto_literal(typ, value, config.quote, config.null_string)
        end
        require_string_wire!(value, code)
        return Proto.string_value(value) if config.preset == Preset::SPANNER_CLI

        return BytesFmt.readable_string_from_base64_wire(Proto.string_value(value))
      end

      if code == TypeCode::ENUM
        return format_enum_literal(typ, value, config.null_string) if config.preset == Preset::LITERAL

        return format_enum_simple(typ, value, config.null_string)
      end

      raise UnknownTypeError, typ.inspect if code == TypeCode::TYPE_CODE_UNSPECIFIED || !scalar_type?(code)

      case config.preset
      when Preset::SIMPLE
        format_scalar_simple(typ, value)
      when Preset::LITERAL
        format_scalar_literal(typ, value, config.quote)
      else
        format_scalar_spanner_cli(typ, value)
      end
    end

    def format_row(types, values, config)
      raise ArgumentError, "len(types)=#{types.length} != len(values)=#{values.length}" if types.length != values.length

      types.zip(values).map { |t, v| format_value(t, v, config, toplevel: true) }
    end
  end
end
