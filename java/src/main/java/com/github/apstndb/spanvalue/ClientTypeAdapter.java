package com.github.apstndb.spanvalue;

import com.google.spanner.v1.StructType;
import com.google.spanner.v1.Type;
import com.google.spanner.v1.TypeAnnotationCode;
import com.google.spanner.v1.TypeCode;
import java.lang.reflect.Method;
import java.util.List;

/** Adapt high-level client types to wire {@link com.google.spanner.v1.Type}. */
public final class ClientTypeAdapter {
  private ClientTypeAdapter() {}

  /**
   * Adapt a {@code com.google.cloud.spanner.Type} (or wire {@link Type}) to wire {@link Type}.
   *
   * <p>The main library does not depend on {@code google-cloud-spanner}; client types are handled
   * via reflection. Wire {@link Type} objects are returned unchanged.
   */
  public static Type adapt(Object clientType) {
    if (clientType == null) {
      throw new IllegalArgumentException("clientType must not be null");
    }
    if (clientType instanceof Type wireType) {
      return wireType;
    }
    if (!"com.google.cloud.spanner.Type".equals(clientType.getClass().getName())) {
      throw new IllegalArgumentException(
          "unsupported client type: " + clientType.getClass().getName());
    }
    return adaptCloudSpannerType(clientType);
  }

  private static Type adaptCloudSpannerType(Object clientType) {
    try {
      Object codeObj = invoke(clientType, "getCode");
      TypeCode wireCode = (TypeCode) invokePackagePrivate(codeObj, "getTypeCode");
      Type.Builder builder = Type.newBuilder().setCode(wireCode);

      Object annObj = invokePackagePrivate(codeObj, "getTypeAnnotationCode");
      if (annObj instanceof TypeAnnotationCode ann
          && ann != TypeAnnotationCode.TYPE_ANNOTATION_CODE_UNSPECIFIED) {
        builder.setTypeAnnotation(ann);
      }

      if (wireCode == TypeCode.PROTO || wireCode == TypeCode.ENUM) {
        String fqn = (String) invoke(clientType, "getProtoTypeFqn");
        if (fqn != null && !fqn.isEmpty()) {
          builder.setProtoTypeFqn(fqn);
        }
      }

      if (wireCode == TypeCode.ARRAY) {
        Object elem = invoke(clientType, "getArrayElementType");
        if (elem != null) {
          builder.setArrayElementType(adapt(elem));
        }
      }

      if (wireCode == TypeCode.STRUCT) {
        @SuppressWarnings("unchecked")
        List<Object> structFields = (List<Object>) invoke(clientType, "getStructFields");
        if (structFields != null && !structFields.isEmpty()) {
          StructType.Builder structBuilder = StructType.newBuilder();
          for (Object field : structFields) {
            String name = (String) invoke(field, "getName");
            Object fieldType = invoke(field, "getType");
            structBuilder.addFields(
                StructType.Field.newBuilder()
                    .setName(name == null ? "" : name)
                    .setType(adapt(fieldType))
                    .build());
          }
          builder.setStructType(structBuilder.build());
        }
      }

      return builder.build();
    } catch (ReflectiveOperationException e) {
      throw new IllegalArgumentException("failed to adapt client type", e);
    }
  }

  private static Object invoke(Object target, String methodName) throws ReflectiveOperationException {
    Method method = target.getClass().getMethod(methodName);
    return method.invoke(target);
  }

  private static Object invokePackagePrivate(Object target, String methodName)
      throws ReflectiveOperationException {
    Method method = target.getClass().getDeclaredMethod(methodName);
    method.setAccessible(true);
    return method.invoke(target);
  }
}
