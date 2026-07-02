#!/usr/bin/env python3
"""Run a literal SELECT on the Spanner emulator and format cells with spanvalue."""

from __future__ import annotations

import os
import sys

os.environ.setdefault("SPANNER_EMULATOR_HOST", "localhost:9010")

from google.cloud import spanner  # noqa: E402

from spanvalue import (  # noqa: E402
    adapt_client_type,
    encode_value,
    format_result_row,
    simple_format_config,
)

SQL = "SELECT 1 AS n, 'hello' AS s, true AS b"


def main() -> int:
    project_id = os.environ.get("SPANNER_PROJECT_ID", "test-project")
    instance_id = os.environ.get("SPANNER_INSTANCE_ID", "test-instance")
    database_id = os.environ.get("SPANNER_DATABASE_ID", "test-db")

    client = spanner.Client(project=project_id)
    database = client.instance(instance_id).database(database_id)
    config = simple_format_config()

    with database.snapshot() as snapshot:
        result_set = snapshot.execute_sql(SQL)
        fields = result_set.metadata.row_type.fields
        col_types = [adapt_client_type(field.type) for field in fields]

        for row in result_set:
            native_values = list(row)

            # Full path for column 0: metadata type → adapt → encode → format cell
            wire_type = col_types[0]
            wire_value = encode_value(wire_type, native_values[0])
            print(f"encode_value (n): {wire_value}")

            formatted = format_result_row(col_types, native_values, config)
            print(f"format_result_row: {formatted}")
            return 0

    print("Query returned no rows.", file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
