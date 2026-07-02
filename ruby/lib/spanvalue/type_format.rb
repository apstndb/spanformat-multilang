# frozen_string_literal: true

module Spanvalue
  module StructMode
    BASE = 0
    RECURSIVE = 1
    RECURSIVE_WITH_NAME = 2
  end

  module ProtoEnumMode
    BASE = 0
    LEAF = 1
    FULL = 2
    LEAF_WITH_KIND = 3
    FULL_WITH_KIND = 4
  end

  module ArrayMode
    BASE = 0
    RECURSIVE = 1
  end

  module UnknownMode
    UNKNOWN = 0
    TYPE_CODE = 1
    VERBOSE = 2
    PANIC = 3
  end

  module TypeAnnotationMode
    SUFFIX = 0
    OMIT = 1
    PRIMARY = 2
  end

  FormatOption = Data.define(:struct, :proto, :enum, :array, :unknown, :type_annotation) do
    def initialize(
      struct: StructMode::BASE,
      proto: ProtoEnumMode::BASE,
      enum: ProtoEnumMode::BASE,
      array: ArrayMode::BASE,
      unknown: UnknownMode::UNKNOWN,
      type_annotation: TypeAnnotationMode::SUFFIX
    )
      super
    end
  end

  FORMAT_OPTION_SIMPLEST = FormatOption.new(
    struct: StructMode::BASE,
    proto: ProtoEnumMode::BASE,
    enum: ProtoEnumMode::BASE,
    array: ArrayMode::BASE,
    unknown: UnknownMode::TYPE_CODE
  )

  FORMAT_OPTION_SIMPLE = FormatOption.new(
    struct: StructMode::BASE,
    proto: ProtoEnumMode::LEAF,
    enum: ProtoEnumMode::LEAF,
    array: ArrayMode::RECURSIVE,
    unknown: UnknownMode::UNKNOWN
  )

  FORMAT_OPTION_NORMAL = FormatOption.new(
    struct: StructMode::RECURSIVE,
    proto: ProtoEnumMode::LEAF,
    enum: ProtoEnumMode::LEAF,
    array: ArrayMode::RECURSIVE,
    unknown: UnknownMode::VERBOSE
  )

  FORMAT_OPTION_VERBOSE = FormatOption.new(
    struct: StructMode::RECURSIVE_WITH_NAME,
    proto: ProtoEnumMode::FULL,
    enum: ProtoEnumMode::FULL,
    array: ArrayMode::RECURSIVE,
    unknown: UnknownMode::VERBOSE
  )

  FORMAT_OPTION_MORE_VERBOSE = FormatOption.new(
    struct: StructMode::RECURSIVE_WITH_NAME,
    proto: ProtoEnumMode::FULL_WITH_KIND,
    enum: ProtoEnumMode::FULL_WITH_KIND,
    array: ArrayMode::RECURSIVE,
    unknown: UnknownMode::VERBOSE
  )

  module TypeFormat
    module_function

    def last_cut(s, sep)
      idx = s.rindex(sep)
      idx ? s[(idx + sep.length)..] : s
    end

    def annotation_suffix(ann)
      return '' if ann == TypeAnnotationCode::TYPE_ANNOTATION_CODE_UNSPECIFIED

      name = TypeAnnotationCode::NAMES[ann]
      name ? "(#{name})" : "(#{ann})"
    end

    def annotation_name(ann)
      TypeAnnotationCode::NAMES.fetch(ann, ann.to_s)
    end

    def format_type_code(code, mode = UnknownMode::VERBOSE)
      name = Codes.type_code_name(code)
      return name if name

      case mode
      when UnknownMode::TYPE_CODE
        code.to_s
      when UnknownMode::VERBOSE
        "UNKNOWN(#{code})"
      when UnknownMode::PANIC
        raise UnknownTypeError, "unknown TypeCode(#{code})"
      else
        'UNKNOWN'
      end
    end

    def format_proto_enum(typ, mode)
      code = Proto.type_code(typ)
      fqn = Proto.proto_type_fqn(typ)
      code_name = format_type_code(code)

      case mode
      when ProtoEnumMode::LEAF
        last_cut(fqn, '.')
      when ProtoEnumMode::FULL
        fqn
      when ProtoEnumMode::LEAF_WITH_KIND
        "#{code_name}<#{last_cut(fqn, '.')}>"
      when ProtoEnumMode::FULL_WITH_KIND
        "#{code_name}<#{fqn}>"
      else
        code_name
      end
    end

    def format_struct_fields(fields, option)
      fields.map do |field|
        type_str = format_type(Proto.field_type(field), option)
        if option.struct == StructMode::RECURSIVE_WITH_NAME && !Proto.field_name(field).empty?
          "#{Proto.field_name(field)} #{type_str}"
        else
          type_str
        end
      end.join(', ')
    end

    def format_type_impl(typ, option)
      code = Proto.type_code(typ)
      if code == TypeCode::ARRAY && option.array != ArrayMode::BASE
        elem = Proto.array_element_type(typ)
        return "ARRAY<#{format_type(elem, option)}>"
      end
      return format_proto_enum(typ, option.proto) if code == TypeCode::PROTO
      return format_proto_enum(typ, option.enum) if code == TypeCode::ENUM
      if code == TypeCode::STRUCT && option.struct != StructMode::BASE
        return "STRUCT<#{format_struct_fields(Proto.struct_fields(typ), option)}>"
      end

      format_type_code(code, option.unknown)
    end

    def format_type(typ, option = FORMAT_OPTION_SIMPLE)
      option ||= FORMAT_OPTION_SIMPLE
      ann = Proto.type_annotation(typ)

      if option.type_annotation == TypeAnnotationMode::OMIT
        return format_type_impl(typ, option)
      end
      if option.type_annotation == TypeAnnotationMode::PRIMARY
        if ann != TypeAnnotationCode::TYPE_ANNOTATION_CODE_UNSPECIFIED
          return annotation_name(ann)
        end
        return format_type_impl(typ, option)
      end

      format_type_impl(typ, option) + annotation_suffix(ann)
    end

    def format_type_simplest(typ)
      format_type(typ, FORMAT_OPTION_SIMPLEST)
    end

    def format_type_simple(typ)
      format_type(typ, FORMAT_OPTION_SIMPLE)
    end

    def format_type_normal(typ)
      format_type(typ, FORMAT_OPTION_NORMAL)
    end

    def format_type_verbose(typ)
      format_type(typ, FORMAT_OPTION_VERBOSE)
    end

    def format_type_more_verbose(typ)
      format_type(typ, FORMAT_OPTION_MORE_VERBOSE)
    end

    def format_type_verbose_annotation_omit(typ)
      format_type(typ, FORMAT_OPTION_VERBOSE.with(type_annotation: TypeAnnotationMode::OMIT))
    end

    def format_type_verbose_annotation_primary(typ)
      format_type(typ, FORMAT_OPTION_VERBOSE.with(type_annotation: TypeAnnotationMode::PRIMARY))
    end
  end
end
