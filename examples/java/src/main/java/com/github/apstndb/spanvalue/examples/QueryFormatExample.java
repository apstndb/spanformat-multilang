package com.github.apstndb.spanvalue.examples;

import com.github.apstndb.spanvalue.SpanValue;
import com.google.cloud.spanner.DatabaseClient;
import com.google.cloud.spanner.DatabaseId;
import com.google.cloud.spanner.ResultSet;
import com.google.cloud.spanner.Spanner;
import com.google.cloud.spanner.SpannerOptions;
import com.google.cloud.spanner.Statement;
import com.google.protobuf.Value;
import com.google.spanner.v1.Type;
import java.util.ArrayList;
import java.util.List;

/** Run a literal SELECT on the Spanner emulator and format cells with spanvalue. */
public final class QueryFormatExample {
  private static final String SQL = "SELECT 1 AS n, 'hello' AS s, true AS b";

  private QueryFormatExample() {}

  public static void main(String[] args) {
    String projectId = env("SPANNER_PROJECT_ID", "test-project");
    String instanceId = env("SPANNER_INSTANCE_ID", "test-instance");
    String databaseId = env("SPANNER_DATABASE_ID", "test-db");

    // Client libraries read SPANNER_EMULATOR_HOST automatically when set.
    SpannerOptions options = SpannerOptions.newBuilder().setProjectId(projectId).build();
    try (Spanner spanner = options.getService()) {
      DatabaseClient db =
          spanner.getDatabaseClient(DatabaseId.of(projectId, instanceId, databaseId));
      try (ResultSet rs = db.singleUse().executeQuery(Statement.of(SQL))) {
        if (!rs.next()) {
          System.err.println("Query returned no rows.");
          System.exit(1);
        }

        List<Object> types = new ArrayList<>();
        List<Object> values = new ArrayList<>();
        for (int i = 0; i < rs.getColumnCount(); i++) {
          Type wireType = SpanValue.adaptClientType(rs.getColumnType(i));
          types.add(wireType);
          values.add(rs.getValue(i));
        }

        Value encoded = SpanValue.encodeValue(types.get(0), values.get(0));
        System.out.println("encodeValue (n): " + encoded);

        List<String> formatted =
            SpanValue.formatResultRow(types, values, SpanValue.simpleFormatConfig());
        System.out.println("formatResultRow: " + formatted);
      }
    }
  }

  private static String env(String key, String defaultValue) {
    String value = System.getenv(key);
    return value == null || value.isBlank() ? defaultValue : value;
  }
}
