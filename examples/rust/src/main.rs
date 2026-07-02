//! Run a literal SELECT on the Spanner emulator and format cells with spanvalue.

use google_cloud_spanner::client::Spanner;
use google_cloud_spanner::statement::Statement;
use google_cloud_spanner::value::{Type as ClientType, TypeCode as ClientTypeCode};
use spanvalue::{
    encode_value, format_result_row, simple_format_config, type_from_parts, NativeValue, Type,
};

const SQL: &str = "SELECT 1 AS n, 'hello' AS s, true AS b";

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    std::env::set_var(
        "SPANNER_EMULATOR_HOST",
        std::env::var("SPANNER_EMULATOR_HOST").unwrap_or_else(|_| "localhost:9010".into()),
    );

    let project_id = std::env::var("SPANNER_PROJECT_ID").unwrap_or_else(|_| "test-project".into());
    let instance_id =
        std::env::var("SPANNER_INSTANCE_ID").unwrap_or_else(|_| "test-instance".into());
    let database_id = std::env::var("SPANNER_DATABASE_ID").unwrap_or_else(|_| "test-db".into());
    let database = format!(
        "projects/{project_id}/instances/{instance_id}/databases/{database_id}"
    );

    let spanner = Spanner::builder().build().await?;
    let db = spanner.database_client(database).build().await?;
    let tx = db.single_use().build();
    let mut result_set = tx
        .execute_query(Statement::builder(SQL).build())
        .await?;
    let config = simple_format_config("<null>")?;

    let metadata = result_set
        .metadata()
        .ok_or("query metadata missing row type")?;
    let col_types: Vec<Type> = metadata
        .column_types()
        .iter()
        .map(client_type_to_spanvalue)
        .collect();

    let mut printed = false;
    while let Some(row_result) = result_set.next().await {
        let row = row_result?;
        let native_values = vec![
            NativeValue::I64(row.get("n")),
            NativeValue::Str(row.get("s")),
            NativeValue::Bool(row.get("b")),
        ];

        let wire_value = encode_value(&col_types[0], &native_values[0])?;
        println!("encode_value (n): {wire_value:?}");

        let formatted = format_result_row(&col_types, &native_values, &config)?;
        println!("format_result_row: {formatted:?}");
        printed = true;
        break;
    }

    if !printed {
        eprintln!("Query returned no rows.");
        std::process::exit(1);
    }

    Ok(())
}

fn client_type_to_spanvalue(typ: &ClientType) -> Type {
    let code = match typ.code() {
        ClientTypeCode::Bool => "BOOL",
        ClientTypeCode::Int64 => "INT64",
        ClientTypeCode::String => "STRING",
        ClientTypeCode::Float64 => "FLOAT64",
        ClientTypeCode::Float32 => "FLOAT32",
        ClientTypeCode::Bytes => "BYTES",
        ClientTypeCode::Timestamp => "TIMESTAMP",
        ClientTypeCode::Date => "DATE",
        ClientTypeCode::Json => "JSON",
        ClientTypeCode::Numeric => "NUMERIC",
        ClientTypeCode::Uuid => "UUID",
        ClientTypeCode::Interval => "INTERVAL",
        ClientTypeCode::Array => "ARRAY",
        ClientTypeCode::Struct => "STRUCT",
        ClientTypeCode::Proto => "PROTO",
        ClientTypeCode::Enum => "ENUM",
        _ => "TYPE_CODE_UNSPECIFIED",
    };
    let array_element_type = typ
        .array_element_type()
        .map(|elem| client_type_to_spanvalue(&elem));
    type_from_parts(Some(code), array_element_type, None, None, None)
}
