# frozen_string_literal: true

require 'json'
require 'minitest/autorun'
require 'pathname'

require_relative '../lib/spanvalue'

class ConformanceTest < Minitest::Test
  CONFORMANCE_PATH = Pathname.new(__dir__).join('../../testdata/conformance.json').expand_path

  TYPE_PRESETS = {
    'simplest' => Spanvalue::FORMAT_OPTION_SIMPLEST,
    'simple' => Spanvalue::FORMAT_OPTION_SIMPLE,
    'normal' => Spanvalue::FORMAT_OPTION_NORMAL,
    'verbose' => Spanvalue::FORMAT_OPTION_VERBOSE,
    'more_verbose' => Spanvalue::FORMAT_OPTION_MORE_VERBOSE
  }.freeze

  QUOTE_POLICIES = {
    'legacy_double' => Spanvalue::LiteralQuoteConfig.new(
      strategy: Spanvalue::QuoteStrategy::LEGACY,
      preferred_quote: Spanvalue::PreferredQuote::DOUBLE
    ),
    'legacy_single' => Spanvalue::LiteralQuoteConfig.new(
      strategy: Spanvalue::QuoteStrategy::LEGACY,
      preferred_quote: Spanvalue::PreferredQuote::SINGLE
    ),
    'always_double' => Spanvalue::LiteralQuoteConfig.new(
      strategy: Spanvalue::QuoteStrategy::ALWAYS,
      preferred_quote: Spanvalue::PreferredQuote::DOUBLE
    ),
    'always_single' => Spanvalue::LiteralQuoteConfig.new(
      strategy: Spanvalue::QuoteStrategy::ALWAYS,
      preferred_quote: Spanvalue::PreferredQuote::SINGLE
    ),
    'min_escape_double' => Spanvalue::LiteralQuoteConfig.new(
      strategy: Spanvalue::QuoteStrategy::MIN_ESCAPE,
      preferred_quote: Spanvalue::PreferredQuote::DOUBLE
    ),
    'min_escape_single' => Spanvalue::LiteralQuoteConfig.new(
      strategy: Spanvalue::QuoteStrategy::MIN_ESCAPE,
      preferred_quote: Spanvalue::PreferredQuote::SINGLE
    )
  }.freeze

  def self.conformance
    @conformance ||= JSON.parse(File.read(CONFORMANCE_PATH, encoding: 'UTF-8'))
  end

  def format_type_preset(name, typ)
    case name
    when 'verbose_annotation_omit'
      Spanvalue.format_type_verbose_annotation_omit(typ)
    when 'verbose_annotation_primary'
      Spanvalue.format_type_verbose_annotation_primary(typ)
    else
      Spanvalue.format_type(typ, TYPE_PRESETS.fetch(name))
    end
  end

  TYPE_PRESETS.each_key do |preset|
    define_method("test_type_cases_#{preset}") do
      self.class.conformance.fetch('type_cases').each do |case_data|
        got = format_type_preset(preset, case_data['type'])
        want = case_data.fetch('expected').fetch(preset)
        assert_equal want, got, "type case #{case_data['name'].inspect} preset #{preset.inspect}"
      end
    end
  end

  %w[verbose_annotation_omit verbose_annotation_primary].each do |preset|
    define_method("test_type_cases_#{preset}") do
      self.class.conformance.fetch('type_cases').each do |case_data|
        got = format_type_preset(preset, case_data['type'])
        want = case_data.fetch('expected').fetch(preset)
        assert_equal want, got, "type case #{case_data['name'].inspect} preset #{preset.inspect}"
      end
    end
  end

  %w[simple literal spanner_cli].each do |preset|
    define_method("test_value_cases_#{preset}") do
      config = case preset
               when 'simple' then Spanvalue.simple_format_config
               when 'literal' then Spanvalue.literal_format_config
               else Spanvalue.spanner_cli_format_config
               end

      self.class.conformance.fetch('value_cases').each do |case_data|
        got = Spanvalue.format_value(case_data['type'], case_data['value'], config)
        want = case_data.fetch('expected').fetch(preset)
        assert_equal want, got, "value case #{case_data['name'].inspect} preset #{preset.inspect}"
      end
    end
  end

  QUOTE_POLICIES.each_key do |policy_name|
    define_method("test_value_literal_quotes_#{policy_name}") do
      config = Spanvalue.literal_format_config(QUOTE_POLICIES.fetch(policy_name))
      self.class.conformance.fetch('value_cases').each do |case_data|
        got = Spanvalue.format_value(case_data['type'], case_data['value'], config)
        want = case_data.fetch('expected').fetch('literal_quotes').fetch(policy_name)
        assert_equal want, got, "value case #{case_data['name'].inspect} quote #{policy_name.inspect}"
      end
    end
  end
end
