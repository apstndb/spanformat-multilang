#!/usr/bin/env bash
# One-time Spanner emulator bootstrap for examples/.
# Prerequisite: emulator running (see examples/README.md).
set -euo pipefail

: "${SPANNER_EMULATOR_HOST:=localhost:9010}"
: "${SPANNER_PROJECT_ID:=test-project}"
: "${SPANNER_INSTANCE_ID:=test-instance}"
: "${SPANNER_DATABASE_ID:=test-db}"

export SPANNER_EMULATOR_HOST

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -n "${SPANNER_EMULATOR_HOST}" ]]; then
  echo "Emulator: ${SPANNER_EMULATOR_HOST}"
  echo "Project:  ${SPANNER_PROJECT_ID}"
  echo "Instance: ${SPANNER_INSTANCE_ID}"
  echo "Database: ${SPANNER_DATABASE_ID}"
  python3 "${SCRIPT_DIR}/bootstrap-emulator.py"
  echo "Ready: projects/${SPANNER_PROJECT_ID}/instances/${SPANNER_INSTANCE_ID}/databases/${SPANNER_DATABASE_ID}"
  exit 0
fi

echo "Emulator: ${SPANNER_EMULATOR_HOST}"
echo "Project:  ${SPANNER_PROJECT_ID}"
echo "Instance: ${SPANNER_INSTANCE_ID}"
echo "Database: ${SPANNER_DATABASE_ID}"

GCLOUD_ARGS=(
  --project="${SPANNER_PROJECT_ID}"
  --quiet
)

if gcloud spanner instances describe "${SPANNER_INSTANCE_ID}" "${GCLOUD_ARGS[@]}" >/dev/null 2>&1; then
  echo "Instance already exists."
else
  gcloud spanner instances create "${SPANNER_INSTANCE_ID}" \
    "${GCLOUD_ARGS[@]}" \
    --config=emulator-config \
    --description="spanvalue examples" \
    --nodes=1
fi

if gcloud spanner databases describe "${SPANNER_DATABASE_ID}" \
  --instance="${SPANNER_INSTANCE_ID}" \
  "${GCLOUD_ARGS[@]}" >/dev/null 2>&1; then
  echo "Database already exists."
else
  gcloud spanner databases create "${SPANNER_DATABASE_ID}" \
    --instance="${SPANNER_INSTANCE_ID}" \
    "${GCLOUD_ARGS[@]}" \
    --ddl=""
fi

echo "Ready: projects/${SPANNER_PROJECT_ID}/instances/${SPANNER_INSTANCE_ID}/databases/${SPANNER_DATABASE_ID}"
