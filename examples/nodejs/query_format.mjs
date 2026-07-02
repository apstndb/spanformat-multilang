#!/usr/bin/env node
/**
 * Run a literal SELECT on the Spanner emulator and format cells with spanvalue.
 */

import { Spanner } from '@google-cloud/spanner';
import {
  adaptClientType,
  encodeValue,
  formatResultRow,
  simpleFormatConfig,
} from '@apstndb/spanvalue';

const SQL = "SELECT 1 AS n, 'hello' AS s, true AS b";

process.env.SPANNER_EMULATOR_HOST ??= 'localhost:9010';

const projectId = process.env.SPANNER_PROJECT_ID ?? 'test-project';
const instanceId = process.env.SPANNER_INSTANCE_ID ?? 'test-instance';
const databaseId = process.env.SPANNER_DATABASE_ID ?? 'test-db';

const spanner = new Spanner({ projectId });
const database = spanner.instance(instanceId).database(databaseId);
const config = simpleFormatConfig();

const [rows, , response] = await database.run({ sql: SQL });
if (rows.length === 0) {
  console.error('Query returned no rows.');
  process.exit(1);
}

const fields = response.metadata.rowType.fields;
const colTypes = fields.map((field) => adaptClientType(field.type));
const row = rows[0];
const nativeValues = fields.map((_, index) => row[index]);

const wireValue = encodeValue(colTypes[0], nativeValues[0]);
console.log(`encodeValue (n): ${JSON.stringify(wireValue)}`);

const formatted = formatResultRow(colTypes, nativeValues, config);
console.log(`formatResultRow: ${JSON.stringify(formatted)}`);
