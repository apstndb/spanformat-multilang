using Apstndb.SpanValue;
using Google.Api.Gax;
using Google.Cloud.Spanner.Data;

// C# ignores SPANNER_EMULATOR_HOST unless emulator detection is enabled.
Environment.SetEnvironmentVariable(
    "SPANNER_EMULATOR_HOST",
    Environment.GetEnvironmentVariable("SPANNER_EMULATOR_HOST") ?? "localhost:9010");

var projectId = Environment.GetEnvironmentVariable("SPANNER_PROJECT_ID") ?? "test-project";
var instanceId = Environment.GetEnvironmentVariable("SPANNER_INSTANCE_ID") ?? "test-instance";
var databaseId = Environment.GetEnvironmentVariable("SPANNER_DATABASE_ID") ?? "test-db";

var builder = new SpannerConnectionStringBuilder
{
    DataSource = $"projects/{projectId}/instances/{instanceId}/databases/{databaseId}",
    // C# ignores SPANNER_EMULATOR_HOST unless emulator detection is enabled.
    EmulatorDetection = EmulatorDetection.EmulatorOnly,
    // Required so GetSchemaTable exposes SpannerDbType per column (metadata timing).
    EnableGetSchemaTable = true,
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

var schema = reader.GetSchemaTable()
    ?? throw new InvalidOperationException("Query metadata missing; set EnableGetSchemaTable=true.");

var types = new List<object?>();
var values = new List<object?>();
for (var i = 0; i < reader.FieldCount; i++)
{
    var spannerType = (SpannerDbType)schema.Rows[i]["ProviderType"];
    types.Add(ValueEncoder.AdaptClientType(spannerType));
    values.Add(reader.IsDBNull(i) ? null : NativeValue(reader, i, spannerType));
}

var encoded = ValueEncoder.EncodeValue(types[0]!, values[0]);
Console.WriteLine($"EncodeValue (n): {encoded}");

var formatted = ValueEncoder.FormatResultRow(types, values, FormatConfigFactory.SimpleFormatConfig());
Console.WriteLine($"FormatResultRow: [{string.Join(", ", formatted.Select(static cell => $"\"{cell}\""))}]");

return 0;

static object? NativeValue(SpannerDataReader reader, int index, SpannerDbType spannerType)
{
    if (spannerType == SpannerDbType.Bool)
        return reader.GetBoolean(index);
    if (spannerType == SpannerDbType.Int64)
        return reader.GetInt64(index);
    if (spannerType == SpannerDbType.String)
        return reader.GetString(index);
    if (spannerType == SpannerDbType.Float64)
        return reader.GetDouble(index);
    if (spannerType == SpannerDbType.Float32)
        return reader.GetFieldValue<float>(index);
    if (spannerType == SpannerDbType.Bytes)
        return reader.GetFieldValue<byte[]>(index);
    if (spannerType == SpannerDbType.Timestamp)
        return reader.GetTimestamp(index);
    if (spannerType == SpannerDbType.Date)
        return reader.GetSpannerDate(index);
    if (spannerType == SpannerDbType.Json || spannerType == SpannerDbType.PgJsonb)
        return reader.GetJsonValue(index);
    if (spannerType == SpannerDbType.Numeric || spannerType == SpannerDbType.PgNumeric)
        return reader.GetNumeric(index);
    if (spannerType == SpannerDbType.Interval)
        return reader.GetInterval(index);

    var value = reader.GetValue(index);
    return value is DBNull ? null : value;
}
