#!/usr/bin/env ruby
# frozen_string_literal: true

# Run a literal SELECT on the Spanner emulator and format cells with spanvalue.

require 'google/cloud/spanner'
require 'spanvalue'

ENV['SPANNER_EMULATOR_HOST'] ||= 'localhost:9010'

SQL = "SELECT 1 AS n, 'hello' AS s, true AS b"

project_id = ENV.fetch('SPANNER_PROJECT_ID', 'test-project')
instance_id = ENV.fetch('SPANNER_INSTANCE_ID', 'test-instance')
database_id = ENV.fetch('SPANNER_DATABASE_ID', 'test-db')

spanner = Google::Cloud::Spanner.new project: project_id
client = spanner.client instance_id, database_id
config = Spanvalue.simple_format_config

result = client.execute SQL
fields = result.metadata.row_type.fields
col_types = fields.map { |field| Spanvalue::ClientTypeAdapter.adapt(field.type) }

result.rows.each do |row|
  native_values = fields.map.with_index { |_field, index| row[index] }

  wire_value = Spanvalue.encode_value(col_types[0], native_values[0])
  puts "encode_value (n): #{wire_value.inspect}"

  formatted = Spanvalue.format_result_row(col_types, native_values, config)
  puts "format_result_row: #{formatted.inspect}"
  exit 0
end

warn 'Query returned no rows.'
exit 1
