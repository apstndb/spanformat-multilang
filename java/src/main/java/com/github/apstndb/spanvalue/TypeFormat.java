package com.github.apstndb.spanvalue;

import static com.github.apstndb.spanvalue.ProtoAccess.arrayElementType;
import static com.github.apstndb.spanvalue.ProtoAccess.fieldName;
import static com.github.apstndb.spanvalue.ProtoAccess.fieldType;
import static com.github.apstndb.spanvalue.ProtoAccess.protoTypeFqn;
import static com.github.apstndb.spanvalue.ProtoAccess.structFields;
import static com.github.apstndb.spanvalue.ProtoAccess.typeAnnotation;
import static com.github.apstndb.spanvalue.ProtoAccess.typeCode;

import java.util.ArrayList;
import java.util.List;

/** Format Cloud Spanner google.spanner.v1.Type values. */
public final class TypeFormat {
  private TypeFormat() {}

  public enum StructMode {
    BASE,
    RECURSIVE,
    RECURSIVE_WITH_NAME
  }

  public enum ProtoEnumMode {
    BASE,
    LEAF,
    FULL,
    LEAF_WITH_KIND,
    FULL_WITH_KIND
  }

  public enum ArrayMode {
    BASE,
    RECURSIVE
  }

  public enum UnknownMode {
    UNKNOWN,
    TYPE_CODE,
    VERBOSE,
    PANIC
  }

  public enum TypeAnnotationMode {
    SUFFIX,
    OMIT,
    PRIMARY
  }

  public record FormatOption(
      StructMode structMode,
      ProtoEnumMode proto,
      ProtoEnumMode enumMode,
      ArrayMode array,
      UnknownMode unknown,
      TypeAnnotationMode typeAnnotation) {

    public FormatOption() {
      this(
          StructMode.BASE,
          ProtoEnumMode.BASE,
          ProtoEnumMode.BASE,
          ArrayMode.BASE,
          UnknownMode.UNKNOWN,
          TypeAnnotationMode.SUFFIX);
    }
  }

  public static final FormatOption FORMAT_OPTION_SIMPLEST =
      new FormatOption(
          StructMode.BASE,
          ProtoEnumMode.BASE,
          ProtoEnumMode.BASE,
          ArrayMode.BASE,
          UnknownMode.TYPE_CODE,
          TypeAnnotationMode.SUFFIX);

  public static final FormatOption FORMAT_OPTION_SIMPLE =
      new FormatOption(
          StructMode.BASE,
          ProtoEnumMode.LEAF,
          ProtoEnumMode.LEAF,
          ArrayMode.RECURSIVE,
          UnknownMode.UNKNOWN,
          TypeAnnotationMode.SUFFIX);

  public static final FormatOption FORMAT_OPTION_NORMAL =
      new FormatOption(
          StructMode.RECURSIVE,
          ProtoEnumMode.LEAF,
          ProtoEnumMode.LEAF,
          ArrayMode.RECURSIVE,
          UnknownMode.VERBOSE,
          TypeAnnotationMode.SUFFIX);

  public static final FormatOption FORMAT_OPTION_VERBOSE =
      new FormatOption(
          StructMode.RECURSIVE_WITH_NAME,
          ProtoEnumMode.FULL,
          ProtoEnumMode.FULL,
          ArrayMode.RECURSIVE,
          UnknownMode.VERBOSE,
          TypeAnnotationMode.SUFFIX);

  public static final FormatOption FORMAT_OPTION_MORE_VERBOSE =
      new FormatOption(
          StructMode.RECURSIVE_WITH_NAME,
          ProtoEnumMode.FULL_WITH_KIND,
          ProtoEnumMode.FULL_WITH_KIND,
          ArrayMode.RECURSIVE,
          UnknownMode.VERBOSE,
          TypeAnnotationMode.SUFFIX);

  private static String lastCut(String s, String sep) {
    int idx = s.lastIndexOf(sep);
    return idx >= 0 ? s.substring(idx + 1) : s;
  }

  private static String annotationSuffix(int ann) {
    if (ann == TypeAnnotationCode.TYPE_ANNOTATION_CODE_UNSPECIFIED.getValue()) {
      return "";
    }
    String name = TypeAnnotationCode.nameFor(ann);
    if (name == null) {
      return "(" + ann + ")";
    }
    return "(" + name + ")";
  }

  private static String annotationName(int ann) {
    String name = TypeAnnotationCode.nameFor(ann);
    return name != null ? name : String.valueOf(ann);
  }

  public static String formatTypeCode(int code, UnknownMode mode) {
    String name = TypeCode.nameFor(code);
    if (name != null) {
      return name;
    }
    return switch (mode) {
      case TYPE_CODE -> String.valueOf(code);
      case VERBOSE -> "UNKNOWN(" + code + ")";
      case PANIC -> throw new UnknownTypeException("unknown TypeCode(" + code + ")");
      default -> "UNKNOWN";
    };
  }

  public static String formatProtoEnum(Object typ, ProtoEnumMode mode) {
    int code = typeCode(typ);
    String fqn = protoTypeFqn(typ);
    String codeName = formatTypeCode(code, UnknownMode.VERBOSE);
    return switch (mode) {
      case LEAF -> lastCut(fqn, ".");
      case FULL -> fqn;
      case LEAF_WITH_KIND -> codeName + "<" + lastCut(fqn, ".") + ">";
      case FULL_WITH_KIND -> codeName + "<" + fqn + ">";
      default -> codeName;
    };
  }

  public static String formatStructFields(List<Object> fields, FormatOption option) {
    List<String> parts = new ArrayList<>();
    for (Object field : fields) {
      String typeStr = formatType(fieldType(field), option);
      if (option.structMode() == StructMode.RECURSIVE_WITH_NAME && !fieldName(field).isEmpty()) {
        parts.add(fieldName(field) + " " + typeStr);
      } else {
        parts.add(typeStr);
      }
    }
    return String.join(", ", parts);
  }

  private static String formatTypeImpl(Object typ, FormatOption option) {
    int code = typeCode(typ);
    if (code == TypeCode.ARRAY.getValue() && option.array() != ArrayMode.BASE) {
      return "ARRAY<" + formatType(arrayElementType(typ), option) + ">";
    }
    if (code == TypeCode.PROTO.getValue()) {
      return formatProtoEnum(typ, option.proto());
    }
    if (code == TypeCode.ENUM.getValue()) {
      return formatProtoEnum(typ, option.enumMode());
    }
    if (code == TypeCode.STRUCT.getValue() && option.structMode() != StructMode.BASE) {
      return "STRUCT<" + formatStructFields(structFields(typ), option) + ">";
    }
    return formatTypeCode(code, option.unknown());
  }

  public static String formatType(Object typ, FormatOption option) {
    if (option == null) {
      option = FORMAT_OPTION_SIMPLE;
    }
    int ann = typeAnnotation(typ);
    return switch (option.typeAnnotation()) {
      case OMIT -> formatTypeImpl(typ, option);
      case PRIMARY -> {
        if (ann != TypeAnnotationCode.TYPE_ANNOTATION_CODE_UNSPECIFIED.getValue()) {
          yield annotationName(ann);
        }
        yield formatTypeImpl(typ, option);
      }
      case SUFFIX -> formatTypeImpl(typ, option) + annotationSuffix(ann);
    };
  }

  public static String formatTypeSimplest(Object typ) {
    return formatType(typ, FORMAT_OPTION_SIMPLEST);
  }

  public static String formatTypeSimple(Object typ) {
    return formatType(typ, FORMAT_OPTION_SIMPLE);
  }

  public static String formatTypeNormal(Object typ) {
    return formatType(typ, FORMAT_OPTION_NORMAL);
  }

  public static String formatTypeVerbose(Object typ) {
    return formatType(typ, FORMAT_OPTION_VERBOSE);
  }

  public static String formatTypeMoreVerbose(Object typ) {
    return formatType(typ, FORMAT_OPTION_MORE_VERBOSE);
  }

  public static String formatTypeVerboseAnnotationOmit(Object typ) {
    return formatType(
        typ,
        new FormatOption(
            FORMAT_OPTION_VERBOSE.structMode(),
            FORMAT_OPTION_VERBOSE.proto(),
            FORMAT_OPTION_VERBOSE.enumMode(),
            FORMAT_OPTION_VERBOSE.array(),
            FORMAT_OPTION_VERBOSE.unknown(),
            TypeAnnotationMode.OMIT));
  }

  public static String formatTypeVerboseAnnotationPrimary(Object typ) {
    return formatType(
        typ,
        new FormatOption(
            FORMAT_OPTION_VERBOSE.structMode(),
            FORMAT_OPTION_VERBOSE.proto(),
            FORMAT_OPTION_VERBOSE.enumMode(),
            FORMAT_OPTION_VERBOSE.array(),
            FORMAT_OPTION_VERBOSE.unknown(),
            TypeAnnotationMode.PRIMARY));
  }
}
