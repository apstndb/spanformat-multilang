#!/usr/bin/env python3
"""Create test instance/database on the Spanner emulator (used by setup-emulator.sh)."""

from __future__ import annotations

import os

os.environ.setdefault("SPANNER_EMULATOR_HOST", "localhost:9010")

from google.cloud import spanner  # noqa: E402


def main() -> None:
    project_id = os.environ.get("SPANNER_PROJECT_ID", "test-project")
    instance_id = os.environ.get("SPANNER_INSTANCE_ID", "test-instance")
    database_id = os.environ.get("SPANNER_DATABASE_ID", "test-db")

    client = spanner.Client(project=project_id)
    instance = client.instance(instance_id)
    if not instance.exists():
        instance.configuration_name = "emulator-config"
        instance.display_name = "spanvalue examples"
        instance.node_count = 1
        instance.create().result()
        print("Created instance.")
    else:
        print("Instance already exists.")

    database = instance.database(database_id)
    if not database.exists():
        database.create().result()
        print("Created database.")
    else:
        print("Database already exists.")


if __name__ == "__main__":
    main()
