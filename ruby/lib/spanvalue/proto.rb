# frozen_string_literal: true

module Spanvalue
  module Proto
    module_function

    def get(obj, *names, default: nil)
      return default if obj.nil?

      if obj.is_a?(Hash)
        names.each do |name|
          key = name.is_a?(Symbol) ? name : name.to_s
          return obj[key] if obj.key?(key)
          camel = camelize(name.to_s)
          return obj[camel] if obj.key?(camel)
        end
        return default
      end

      names.each do |name|
        return obj.public_send(name) if obj.respond_to?(name)
      end

      if obj.respond_to?(:[])
        names.each do |name|
          val = obj[name]
          return val unless val.nil?
        end
      end

      default
    end

    def camelize(s)
      parts = s.split('_')
      parts[0] + parts[1..].map(&:capitalize).join
    end
    private_class_method :camelize

    def type_code(typ)
      Codes.parse_type_code(get(typ, :code, :Code))
    end

    def type_annotation(typ)
      Codes.parse_type_annotation(get(typ, :type_annotation, :typeAnnotation, :TypeAnnotation))
    end

    def proto_type_fqn(typ)
      get(typ, :proto_type_fqn, :protoTypeFqn, :ProtoTypeFqn, default: '') || ''
    end

    def array_element_type(typ)
      get(typ, :array_element_type, :arrayElementType, :ArrayElementType)
    end

    def struct_type(typ)
      get(typ, :struct_type, :structType, :StructType)
    end

    def struct_fields(typ)
      st = struct_type(typ)
      return [] if st.nil?

      fields = get(st, :fields, :Fields, default: [])
      fields.nil? ? [] : fields.to_a
    end

    def field_name(field)
      get(field, :name, :Name, default: '') || ''
    end

    def field_type(field)
      get(field, :type, :Type)
    end

    def value_kind(value)
      return 'null' if value.nil?

      if value.is_a?(Hash)
        return 'null' if value.key?('null_value') || value.key?('nullValue')
        return 'bool' if value.key?('bool_value') || value.key?('boolValue')
        return 'number' if value.key?('number_value') || value.key?('numberValue')
        return 'string' if value.key?('string_value') || value.key?('stringValue')
        return 'list' if value.key?('list_value') || value.key?('listValue')

        return 'missing'
      end

      return 'bool' if value.is_a?(TrueClass) || value.is_a?(FalseClass)
      return 'number' if value.is_a?(Numeric)
      return 'string' if value.is_a?(String)
      return 'list' if value.is_a?(Array)

      if value.respond_to?(:WhichOneof)
        which = value.WhichOneof('kind')
        return 'missing' if which.nil?

        case which
        when 'null_value', 'nullValue' then return 'null'
        when 'bool_value', 'boolValue' then return 'bool'
        when 'number_value', 'numberValue' then return 'number'
        when 'string_value', 'stringValue' then return 'string'
        when 'list_value', 'listValue' then return 'list'
        end
      end

      %i[bool_value boolValue number_value numberValue string_value stringValue list_value listValue].each do |attr|
        next unless value.respond_to?(attr)

        val = value.public_send(attr)
        kind = attr.to_s.sub(/_?value$/i, '').downcase
        kind = 'bool' if kind == 'bool'
        kind = 'number' if kind == 'number'
        kind = 'string' if kind == 'string'
        kind = 'list' if kind == 'list'
        return kind unless val.nil?
      end

      'missing'
    end

    def null_value?(value)
      %w[null missing].include?(value_kind(value))
    end

    def bool_value(value)
      return value if value.is_a?(TrueClass) || value.is_a?(FalseClass)
      return !!get(value, :bool_value, :boolValue) if value.is_a?(Hash)

      !!get(value, :bool_value, :boolValue)
    end

    def number_value(value)
      return value.to_f if value.is_a?(Numeric)
      return get(value, :number_value, :numberValue).to_f if value.is_a?(Hash)

      get(value, :number_value, :numberValue).to_f
    end

    def string_value(value)
      return value if value.is_a?(String)
      return get(value, :string_value, :stringValue, default: '').to_s if value.is_a?(Hash)

      get(value, :string_value, :stringValue, default: '').to_s
    end

    def list_values(value)
      return value if value.is_a?(Array)

      if value.is_a?(Hash)
        lv = value['list_value'] || value['listValue']
        if lv.is_a?(Hash)
          vals = lv['values'] || lv['Values'] || []
          return vals.to_a
        end
        return lv.values.to_a if lv.respond_to?(:values)
      end

      lv = get(value, :list_value, :listValue)
      return [] if lv.nil?

      vals = get(lv, :values, :Values, default: [])
      vals.nil? ? [] : vals.to_a
    end
  end
end
