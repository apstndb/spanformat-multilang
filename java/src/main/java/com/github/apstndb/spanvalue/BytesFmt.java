package com.github.apstndb.spanvalue;

import java.util.Base64;
import java.util.Locale;

/** BYTES/PROTO readable formatting. */
public final class BytesFmt {
  private BytesFmt() {}

  public static byte[] decodeBase64Wire(String wire) {
    if (wire.isEmpty()) {
      return new byte[0];
    }
    return Base64.getDecoder().decode(wire);
  }

  private static boolean isReadableAscii(byte[] data) {
    for (byte c : data) {
      int b = c & 0xFF;
      if (b == '\\' || b < 0x20 || b > 0x7E) {
        return false;
      }
    }
    return true;
  }

  public static String readableBytesString(byte[] data) {
    if (data.length == 0) {
      return "";
    }
    if (isReadableAscii(data)) {
      return new String(data, java.nio.charset.StandardCharsets.US_ASCII);
    }
    StringBuilder parts = new StringBuilder();
    for (byte b : data) {
      parts.append(Quote.escapeRune(b & 0xFF, false, (char) 0));
    }
    return parts.toString();
  }

  public static String readableStringFromBase64Wire(String wire) {
    return readableBytesString(decodeBase64Wire(wire));
  }
}
