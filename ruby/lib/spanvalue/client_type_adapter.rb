# frozen_string_literal: true

module Spanvalue
  module ClientTypeAdapter
    module_function

    def adapt(client_type)
      return { code: 'TYPE_CODE_UNSPECIFIED' } if client_type.nil?

      code = Proto.type_code(client_type)
      name = Codes.type_code_name(code)
      wire = { code: name || code }

      ann = Proto.type_annotation(client_type)
      if !ann.nil? && ann != TypeAnnotationCode::TYPE_ANNOTATION_CODE_UNSPECIFIED
        wire[:typeAnnotation] = TypeAnnotationCode::NAMES[ann] || ann
      end

      fqn = Proto.proto_type_fqn(client_type)
      wire[:protoTypeFqn] = fqn unless fqn.empty?

      elem = Proto.array_element_type(client_type)
      wire[:arrayElementType] = adapt(elem) unless elem.nil?

      st = Proto.struct_type(client_type)
      unless st.nil?
        wire[:structType] = {
          fields: Proto.struct_fields(client_type).map do |field|
            out = { type: adapt(Proto.field_type(field)) }
            name = Proto.field_name(field)
            out[:name] = name unless name.empty?
            out
          end
        }
      end

      wire
    end
  end
end
