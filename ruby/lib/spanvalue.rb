# frozen_string_literal: true

require_relative 'spanvalue/version'
require_relative 'spanvalue/errors'
require_relative 'spanvalue/codes'
require_relative 'spanvalue/proto'
require_relative 'spanvalue/quote'
require_relative 'spanvalue/bytes_fmt'
require_relative 'spanvalue/float_fmt'
require_relative 'spanvalue/type_format'
require_relative 'spanvalue/format_config'

module Spanvalue
  class << self
    def format_type(typ, option = FORMAT_OPTION_SIMPLE)
      TypeFormat.format_type(typ, option)
    end

    def format_type_simplest(typ)
      TypeFormat.format_type_simplest(typ)
    end

    def format_type_simple(typ)
      TypeFormat.format_type_simple(typ)
    end

    def format_type_normal(typ)
      TypeFormat.format_type_normal(typ)
    end

    def format_type_verbose(typ)
      TypeFormat.format_type_verbose(typ)
    end

    def format_type_more_verbose(typ)
      TypeFormat.format_type_more_verbose(typ)
    end

    def format_type_verbose_annotation_omit(typ)
      TypeFormat.format_type_verbose_annotation_omit(typ)
    end

    def format_type_verbose_annotation_primary(typ)
      TypeFormat.format_type_verbose_annotation_primary(typ)
    end

    def format_type_code(code, mode = UnknownMode::VERBOSE)
      TypeFormat.format_type_code(code, mode)
    end

    def format_proto_enum(typ, mode)
      TypeFormat.format_proto_enum(typ, mode)
    end

    def format_struct_fields(fields, option)
      TypeFormat.format_struct_fields(fields, option)
    end

    def simple_format_config(null_string = '<null>')
      FormatConfigFactory.simple_format_config(null_string)
    end

    def literal_format_config(quote = nil, null_string: 'NULL')
      FormatConfigFactory.literal_format_config(quote, null_string: null_string)
    end

    def spanner_cli_format_config(null_string = 'NULL')
      FormatConfigFactory.spanner_cli_format_config(null_string)
    end

    def format_value(typ, value, config, toplevel: true)
      ValueFormat.format_value(typ, value, config, toplevel: toplevel)
    end

    def format_row(types, values, config)
      ValueFormat.format_row(types, values, config)
    end
  end
end
