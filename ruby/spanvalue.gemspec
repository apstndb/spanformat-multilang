# frozen_string_literal: true

require_relative 'lib/spanvalue/version'

Gem::Specification.new do |spec|
  spec.name = 'spanvalue'
  spec.version = Spanvalue::VERSION
  spec.authors = ['apstndb']
  spec.email = ['']

  spec.summary = 'Format Cloud Spanner types and column values'
  spec.description = 'Format Cloud Spanner google.spanner.v1.Type and google.protobuf.Value values'
  spec.homepage = 'https://github.com/apstndb/spanvalue-multilang'
  spec.license = 'MIT'
  spec.required_ruby_version = '>= 3.2'

  spec.files = Dir.glob('{lib,test}/**/*', base: __dir__).select { |f| File.file?(File.join(__dir__, f)) } +
               ['spanvalue.gemspec']
  spec.require_paths = ['lib']

  spec.add_development_dependency 'minitest', '~> 5.0'
end
