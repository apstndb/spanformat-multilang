//! BYTES/PROTO readable formatting.

use crate::errors::{FormatError, MalformedWireError, Result};
use crate::quote::escape_rune;

pub fn decode_base64_wire(wire: &str) -> Result<Vec<u8>> {
    if wire.is_empty() {
        return Ok(Vec::new());
    }
    decode_standard_base64(wire).map_err(|e| FormatError::MalformedWire(MalformedWireError::new(e)))
}

fn decode_standard_base64(input: &str) -> std::result::Result<Vec<u8>, String> {
    const DECODE: [i8; 128] = {
        let mut table = [-1i8; 128];
        let mut i = 0usize;
        while i < 26 {
            table[b'A' as usize + i] = i as i8;
            table[b'a' as usize + i] = (i + 26) as i8;
            i += 1;
        }
        let mut d = 0usize;
        while d < 10 {
            table[b'0' as usize + d] = (d + 52) as i8;
            d += 1;
        }
        table[b'+' as usize] = 62;
        table[b'/' as usize] = 63;
        table
    };

    let bytes = input.as_bytes();
    let mut out = Vec::with_capacity(bytes.len() * 3 / 4);
    let mut buf = 0u32;
    let mut bits = 0u32;

    for &b in bytes {
        if b == b'=' {
            break;
        }
        if b >= 128 {
            return Err(format!("invalid base64 byte {b}"));
        }
        let val = DECODE[b as usize];
        if val < 0 {
            continue;
        }
        buf = (buf << 6) | val as u32;
        bits += 6;
        if bits >= 8 {
            bits -= 8;
            out.push((buf >> bits) as u8);
            buf &= (1 << bits) - 1;
        }
    }
    Ok(out)
}

fn is_readable_ascii(data: &[u8]) -> bool {
    data.iter().all(|&c| c != b'\\' && (0x20..=0x7E).contains(&c))
}

pub fn readable_bytes_string(data: &[u8]) -> String {
    if data.is_empty() {
        return String::new();
    }
    if is_readable_ascii(data) {
        return String::from_utf8_lossy(data).into_owned();
    }
    let mut out = String::with_capacity(data.len());
    for &b in data {
        out.push_str(&escape_rune(u32::from(b), false, None));
    }
    out
}

pub fn readable_string_from_base64_wire(wire: &str) -> Result<String> {
    Ok(readable_bytes_string(&decode_base64_wire(wire)?))
}

/// RFC 4648 standard base64 with padding (Spanner BYTES/PROTO wire form).
pub fn encode_base64_wire(data: &[u8]) -> String {
    const ENCODE: &[u8; 64] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    if data.is_empty() {
        return String::new();
    }
    let mut out = String::with_capacity((data.len() + 2) / 3 * 4);
    let mut i = 0usize;
    while i + 3 <= data.len() {
        let n = u32::from(data[i]) << 16 | u32::from(data[i + 1]) << 8 | u32::from(data[i + 2]);
        out.push(ENCODE[((n >> 18) & 63) as usize] as char);
        out.push(ENCODE[((n >> 12) & 63) as usize] as char);
        out.push(ENCODE[((n >> 6) & 63) as usize] as char);
        out.push(ENCODE[(n & 63) as usize] as char);
        i += 3;
    }
    let rem = data.len() - i;
    if rem == 1 {
        let n = u32::from(data[i]) << 16;
        out.push(ENCODE[((n >> 18) & 63) as usize] as char);
        out.push(ENCODE[((n >> 12) & 63) as usize] as char);
        out.push('=');
        out.push('=');
    } else if rem == 2 {
        let n = u32::from(data[i]) << 16 | u32::from(data[i + 1]) << 8;
        out.push(ENCODE[((n >> 18) & 63) as usize] as char);
        out.push(ENCODE[((n >> 12) & 63) as usize] as char);
        out.push(ENCODE[((n >> 6) & 63) as usize] as char);
        out.push('=');
    }
    out
}
