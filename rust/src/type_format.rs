//! Format Cloud Spanner `google.spanner.v1.Type` values.

use crate::codes::{
    type_annotation_name, type_code_name, TypeAnnotationCode, TypeCode,
};
use crate::errors::{FormatError, Result, UnknownTypeError};
use crate::types::{array_element_type, field_name, proto_type_fqn, struct_fields, Type};

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum StructMode {
    Base = 0,
    Recursive = 1,
    RecursiveWithName = 2,
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum ProtoEnumMode {
    Base = 0,
    Leaf = 1,
    Full = 2,
    LeafWithKind = 3,
    FullWithKind = 4,
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum ArrayMode {
    Base = 0,
    Recursive = 1,
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum UnknownMode {
    Unknown = 0,
    TypeCode = 1,
    Verbose = 2,
    Panic = 3,
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum TypeAnnotationMode {
    Suffix = 0,
    Omit = 1,
    Primary = 2,
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub struct FormatOption {
    pub struct_mode: StructMode,
    pub proto: ProtoEnumMode,
    pub enum_mode: ProtoEnumMode,
    pub array: ArrayMode,
    pub unknown: UnknownMode,
    pub type_annotation: TypeAnnotationMode,
}

pub const FORMAT_OPTION_SIMPLEST: FormatOption = FormatOption {
    struct_mode: StructMode::Base,
    proto: ProtoEnumMode::Base,
    enum_mode: ProtoEnumMode::Base,
    array: ArrayMode::Base,
    unknown: UnknownMode::TypeCode,
    type_annotation: TypeAnnotationMode::Suffix,
};

pub const FORMAT_OPTION_SIMPLE: FormatOption = FormatOption {
    struct_mode: StructMode::Base,
    proto: ProtoEnumMode::Leaf,
    enum_mode: ProtoEnumMode::Leaf,
    array: ArrayMode::Recursive,
    unknown: UnknownMode::Unknown,
    type_annotation: TypeAnnotationMode::Suffix,
};

pub const FORMAT_OPTION_NORMAL: FormatOption = FormatOption {
    struct_mode: StructMode::Recursive,
    proto: ProtoEnumMode::Leaf,
    enum_mode: ProtoEnumMode::Leaf,
    array: ArrayMode::Recursive,
    unknown: UnknownMode::Verbose,
    type_annotation: TypeAnnotationMode::Suffix,
};

pub const FORMAT_OPTION_VERBOSE: FormatOption = FormatOption {
    struct_mode: StructMode::RecursiveWithName,
    proto: ProtoEnumMode::Full,
    enum_mode: ProtoEnumMode::Full,
    array: ArrayMode::Recursive,
    unknown: UnknownMode::Verbose,
    type_annotation: TypeAnnotationMode::Suffix,
};

pub const FORMAT_OPTION_MORE_VERBOSE: FormatOption = FormatOption {
    struct_mode: StructMode::RecursiveWithName,
    proto: ProtoEnumMode::FullWithKind,
    enum_mode: ProtoEnumMode::FullWithKind,
    array: ArrayMode::Recursive,
    unknown: UnknownMode::Verbose,
    type_annotation: TypeAnnotationMode::Suffix,
};

fn last_cut(s: &str, sep: char) -> &str {
    s.rsplit_once(sep).map(|(_, tail)| tail).unwrap_or(s)
}

fn annotation_suffix(ann: i32) -> String {
    if ann == TypeAnnotationCode::TypeAnnotationCodeUnspecified as i32 {
        return String::new();
    }
    match type_annotation_name(ann) {
        Some(name) => format!("({name})"),
        None => format!("({ann})"),
    }
}

fn annotation_name(ann: i32) -> String {
    type_annotation_name(ann)
        .map(str::to_string)
        .unwrap_or_else(|| ann.to_string())
}

pub fn format_type_code(code: i32, mode: UnknownMode) -> Result<String> {
    if let Some(name) = type_code_name(code) {
        return Ok(name.to_string());
    }
    match mode {
        UnknownMode::TypeCode => Ok(code.to_string()),
        UnknownMode::Verbose => Ok(format!("UNKNOWN({code})")),
        UnknownMode::Panic => Err(FormatError::UnknownType(UnknownTypeError::new(format!(
            "unknown TypeCode({code})"
        )))),
        UnknownMode::Unknown => Ok("UNKNOWN".to_string()),
    }
}

pub fn format_proto_enum(typ: &Type, mode: ProtoEnumMode) -> String {
    let code = typ.code;
    let fqn = proto_type_fqn(typ);
    let code_name = type_code_name(code).unwrap_or("UNKNOWN");
    match mode {
        ProtoEnumMode::Leaf => last_cut(fqn, '.').to_string(),
        ProtoEnumMode::Full => fqn.to_string(),
        ProtoEnumMode::LeafWithKind => format!("{code_name}<{}>", last_cut(fqn, '.')),
        ProtoEnumMode::FullWithKind => format!("{code_name}<{fqn}>"),
        ProtoEnumMode::Base => code_name.to_string(),
    }
}

pub fn format_struct_fields(fields: &[crate::types::Field], option: FormatOption) -> Result<String> {
    let mut parts = Vec::with_capacity(fields.len());
    for field in fields {
        let type_str = format_type(&field.field_type, Some(option))?;
        if option.struct_mode == StructMode::RecursiveWithName && !field_name(field).is_empty() {
            parts.push(format!("{} {type_str}", field_name(field)));
        } else {
            parts.push(type_str);
        }
    }
    Ok(parts.join(", "))
}

fn format_type_impl(typ: &Type, option: FormatOption) -> Result<String> {
    let code = typ.code;
    if code == TypeCode::Array as i32 && option.array != ArrayMode::Base {
        let elem = array_element_type(typ)
            .ok_or_else(|| FormatError::UnknownType(UnknownTypeError::new("ARRAY missing element type")))?;
        return Ok(format!("ARRAY<{}>", format_type(elem, Some(option))?));
    }
    if code == TypeCode::Proto as i32 {
        return Ok(format_proto_enum(typ, option.proto));
    }
    if code == TypeCode::Enum as i32 {
        return Ok(format_proto_enum(typ, option.enum_mode));
    }
    if code == TypeCode::Struct as i32 && option.struct_mode != StructMode::Base {
        return Ok(format!(
            "STRUCT<{}>",
            format_struct_fields(struct_fields(typ), option)?
        ));
    }
    format_type_code(code, option.unknown)
}

pub fn format_type(typ: &Type, option: Option<FormatOption>) -> Result<String> {
    let option = option.unwrap_or(FORMAT_OPTION_SIMPLE);
    let ann = typ.type_annotation;
    match option.type_annotation {
        TypeAnnotationMode::Omit => format_type_impl(typ, option),
        TypeAnnotationMode::Primary => {
            if ann != TypeAnnotationCode::TypeAnnotationCodeUnspecified as i32 {
                Ok(annotation_name(ann))
            } else {
                format_type_impl(typ, option)
            }
        }
        TypeAnnotationMode::Suffix => {
            let mut out = format_type_impl(typ, option)?;
            out.push_str(&annotation_suffix(ann));
            Ok(out)
        }
    }
}

pub fn format_type_simplest(typ: &Type) -> Result<String> {
    format_type(typ, Some(FORMAT_OPTION_SIMPLEST))
}

pub fn format_type_simple(typ: &Type) -> Result<String> {
    format_type(typ, Some(FORMAT_OPTION_SIMPLE))
}

pub fn format_type_normal(typ: &Type) -> Result<String> {
    format_type(typ, Some(FORMAT_OPTION_NORMAL))
}

pub fn format_type_verbose(typ: &Type) -> Result<String> {
    format_type(typ, Some(FORMAT_OPTION_VERBOSE))
}

pub fn format_type_more_verbose(typ: &Type) -> Result<String> {
    format_type(typ, Some(FORMAT_OPTION_MORE_VERBOSE))
}

pub fn format_type_verbose_annotation_omit(typ: &Type) -> Result<String> {
    format_type(
        typ,
        Some(FormatOption {
            type_annotation: TypeAnnotationMode::Omit,
            ..FORMAT_OPTION_VERBOSE
        }),
    )
}

pub fn format_type_verbose_annotation_primary(typ: &Type) -> Result<String> {
    format_type(
        typ,
        Some(FormatOption {
            type_annotation: TypeAnnotationMode::Primary,
            ..FORMAT_OPTION_VERBOSE
        }),
    )
}
