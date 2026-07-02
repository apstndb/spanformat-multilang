using Apstndb.SpanValue;
using Google.Cloud.Spanner.Data;

Environment.SetEnvironmentVariable(
    "SPANNER_EMULATOR_HOST",
    Environment.GetEnvironmentVariable("SPANNER_EMULATOR_HOST") ?? "localhost:9010");

var projectId = Environment.GetEnvironmentVariable("SPANNER_PROJECT_ID") ?? "test-project";
var instanceId = Environment.GetEnvironmentVariable("SPANNER_INSTANCE_ID") ?? "test-instance";
var databaseId = Environment.GetEnvironmentVariable("SPANNER_DATABASE_ID") ?? "test-db";

var builder = new SpannerConnectionStringBuilder
{
    DataSource = $"projects/{projectId}/instances/{instanceId}/databases/{databaseId}",
};

const string Sql = "SELECT 1 AS n, 'hello' AS s, true AS b";

using var connection = new SpannerConnection(builder);
await connection.OpenAsync();

using var command = connection.CreateSelectCommand(Sql);
using var reader = await command.ExecuteReaderAsync();

if (!await reader.ReadAsync())
{
    await Console.Error.WriteLineAsync("Query returned no rows.");
    return 1;
}

var types = new List<object?>();
var values = new List<object?>();
for (var i = 0; i < reader.FieldCount; i++)
{
    types.Add(ValueEncoder.AdaptClientType(reader.GetSpannerDbType(i)));
    var value = reader.GetValue(i);
    values.Add(value is DBNull ? null : value);
}

var encoded = ValueEncoder.EncodeValue(types[0]!, values[0]);
Console.WriteLine($"EncodeValue (n): {encoded}");

var formatted = ValueEncoder.FormatResultRow(types, values, FormatConfigFactory.SimpleFormatConfig());
Console.WriteLine($"FormatResultRow: [{string.Join(", ", formatted.Select(static cell => $"\"{cell}\""))}]");

return 0;
