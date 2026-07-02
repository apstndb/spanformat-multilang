package com.github.apstndb.spanvalue;

import static org.junit.jupiter.api.Assertions.assertEquals;

import com.google.cloud.spanner.Type;
import com.google.spanner.v1.StructType;
import com.google.spanner.v1.TypeAnnotationCode;
import com.google.spanner.v1.TypeCode;
import org.junit.jupiter.api.Test;

class ClientTypeAdapterTest {
  @Test
  void adaptStructFields() {
    Type clientType =
        Type.struct(
            Type.StructField.of("n", Type.int64()),
            Type.StructField.of("s", Type.string()));

    com.google.spanner.v1.Type wire = ClientTypeAdapter.adapt(clientType);

    assertEquals(TypeCode.STRUCT, wire.getCode());
    assertEquals(2, wire.getStructType().getFieldsCount());
    StructType.Field first = wire.getStructType().getFields(0);
    assertEquals("n", first.getName());
    assertEquals(TypeCode.INT64, first.getType().getCode());
    StructType.Field second = wire.getStructType().getFields(1);
    assertEquals("s", second.getName());
    assertEquals(TypeCode.STRING, second.getType().getCode());
  }

  @Test
  void adaptArray() {
    Type clientType = Type.array(Type.int64());
    com.google.spanner.v1.Type wire = ClientTypeAdapter.adapt(clientType);
    assertEquals(TypeCode.ARRAY, wire.getCode());
    assertEquals(TypeCode.INT64, wire.getArrayElementType().getCode());
  }

  @Test
  void adaptPgDialectTypes() {
    com.google.spanner.v1.Type pgNumeric = ClientTypeAdapter.adapt(Type.pgNumeric());
    assertEquals(TypeCode.NUMERIC, pgNumeric.getCode());
    assertEquals(TypeAnnotationCode.PG_NUMERIC, pgNumeric.getTypeAnnotation());

    com.google.spanner.v1.Type pgOid = ClientTypeAdapter.adapt(Type.pgOid());
    assertEquals(TypeCode.INT64, pgOid.getCode());
    assertEquals(TypeAnnotationCode.PG_OID, pgOid.getTypeAnnotation());
  }

  @Test
  void adaptProtoEnum() {
    com.google.spanner.v1.Type wire = ClientTypeAdapter.adapt(Type.protoEnum("examples.Genre"));
    assertEquals(TypeCode.ENUM, wire.getCode());
    assertEquals("examples.Genre", wire.getProtoTypeFqn());
  }
}
