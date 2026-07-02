package com.github.apstndb.spanvalue;

import java.math.BigDecimal;
import java.math.BigInteger;
import java.math.MathContext;
import java.math.RoundingMode;
import java.util.Locale;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Go strconv.FormatFloat(v, 'g', -1, bits) compatible formatting. */
public final class FloatFmt {
  private FloatFmt() {}

  private static final Pattern E_RE =
      Pattern.compile("^(\\d)(?:\\.(\\d+))?e([+-])(\\d+)$");

  public static float narrowFloat32(double v) {
    return Float.intBitsToFloat(Float.floatToRawIntBits((float) v));
  }

  private static float packFloat32(double v) {
    return narrowFloat32(v);
  }

  private static BigDecimal exactBigDecimal(double d) {
    long bits = Double.doubleToRawLongBits(d);
    boolean negative = (bits >> 63) != 0;
    int biasedExp = (int) ((bits >> 52) & 0x7FF);
    long fraction = bits & 0x000FFFFFFFFFFFFFL;
    if (biasedExp == 0x7FF) {
      throw new ArithmeticException("NaN or infinite");
    }

    BigInteger significand;
    int exponent;
    if (biasedExp == 0) {
      if (fraction == 0) {
        return BigDecimal.ZERO;
      }
      exponent = -1022 - 52;
      significand = BigInteger.valueOf(fraction);
      while (significand.bitLength() <= 52) {
        significand = significand.shiftLeft(1);
        exponent--;
      }
    } else {
      exponent = biasedExp - 1023 - 52;
      significand = BigInteger.valueOf(fraction | (1L << 52));
    }
    if (negative) {
      significand = significand.negate();
    }
    if (exponent >= 0) {
      significand = significand.shiftLeft(exponent);
      return new BigDecimal(significand);
    }
    BigInteger divisor = BigInteger.ONE.shiftLeft(-exponent);
    return new BigDecimal(significand)
        .divide(new BigDecimal(divisor), MathContext.DECIMAL128);
  }

  private static String formatFixed(double v, int precision) {
    return exactBigDecimal(v).setScale(precision, RoundingMode.HALF_EVEN).toPlainString();
  }

  private static boolean roundTrips(String s, double original, int bits) {
    try {
      double parsed = Double.parseDouble(s);
      if (Double.isNaN(original)) {
        return Double.isNaN(parsed);
      }
      if (Double.isInfinite(original)) {
        return Double.isInfinite(parsed) && (parsed > 0) == (original > 0);
      }
      if (bits == 32) {
        return packFloat32(parsed) == packFloat32(original);
      }
      return parsed == original;
    } catch (NumberFormatException e) {
      return false;
    }
  }

  private static String fmtExponent(int exp) {
    if (exp >= 0) {
      return String.format(Locale.US, "e+%02d", exp);
    }
    int ae = Math.abs(exp);
    if (ae < 10) {
      return String.format(Locale.US, "e-%02d", ae);
    }
    return "e" + exp;
  }

  private static String peToGoG(String es) {
    Matcher m = E_RE.matcher(es);
    if (!m.matches()) {
      throw new IllegalArgumentException("unexpected e-format: " + es);
    }
    String d1 = m.group(1);
    String rest = m.group(2) != null ? m.group(2) : "";
    String esign = m.group(3);
    String eexp = m.group(4);
    int exp = Integer.parseInt(esign + eexp);

    String sig = d1 + rest.replaceAll("0+$", "");
    if (sig.isEmpty()) {
      sig = "0";
    }
    int ndigits = sig.length();

    if (-4 <= exp && exp < 6) {
      int decPos = 1 + exp;
      String s;
      if (decPos <= 0) {
        s = "0." + "0".repeat(-decPos) + sig;
      } else if (decPos >= ndigits) {
        s = sig + "0".repeat(decPos - ndigits);
      } else {
        s = sig.substring(0, decPos) + "." + sig.substring(decPos);
      }
      if (s.contains(".")) {
        s = s.replaceAll("0+$", "").replaceAll("\\.$", "");
      }
      return s;
    }

    String body;
    if (ndigits == 1) {
      body = sig;
    } else {
      body = sig.charAt(0) + "." + sig.substring(1);
    }
    return body + fmtExponent(exp);
  }

  public static String formatGoG(double v, int bits) {
    if (bits == 32) {
      v = narrowFloat32(v);
    }

    if (Double.isNaN(v)) {
      return "NaN";
    }
    if (Double.isInfinite(v)) {
      return v < 0 ? "-Inf" : "+Inf";
    }
    if (v == 0.0 && Double.doubleToRawLongBits(v) < 0) {
      return "-0";
    }

    boolean negative = v < 0;
    double av = negative ? -v : v;
    int maxP = bits == 64 ? 16 : 8;

    String best = null;
    double target = bits == 64 ? v : narrowFloat32(v);
    for (int p = 0; p <= maxP; p++) {
      String es = String.format(Locale.US, "%." + p + "e", av);
      String g = peToGoG(es);
      String candidate = (negative ? "-" : "") + g;
      if (roundTrips(candidate, target, bits)) {
        if (best == null || candidate.length() < best.length()) {
          best = candidate;
        }
      }
    }

    if (best == null) {
      best = (negative ? "-" : "") + Double.toString(av);
    }
    return best;
  }

  public static String formatSpannerCliFloat(double v, int bits) {
    if (bits == 32) {
      v = narrowFloat32(v);
    }
    if (Double.isNaN(v)) {
      return "NaN";
    }
    if (Double.isInfinite(v)) {
      return v < 0 ? "-Inf" : "+Inf";
    }
    if (v == Math.rint(v) && !Double.isInfinite(v)) {
      if (v == 0.0 && Double.doubleToRawLongBits(v) < 0) {
        return "-0";
      }
      return formatFixed(v, 0);
    }
    return formatFixed(v, 6);
  }

  public static String float64ToLiteral(double v, Quote.LiteralQuoteConfig quoteCfg) {
    if (Double.isNaN(v)) {
      return Quote.sqlCastQuoted("nan", "FLOAT64", quoteCfg);
    }
    if (Double.isInfinite(v)) {
      return Quote.sqlCastQuoted(v < 0 ? "-inf" : "inf", "FLOAT64", quoteCfg);
    }
    String s = formatGoG(v, 64);
    if (s.indexOf('.') < 0 && s.indexOf('e') < 0 && s.indexOf('E') < 0) {
      s += ".0";
    }
    return s;
  }

  public static String float32ToLiteral(double v, Quote.LiteralQuoteConfig quoteCfg) {
    float fv = narrowFloat32(v);
    if (Float.isNaN(fv)) {
      return Quote.sqlCastQuoted("nan", "FLOAT32", quoteCfg);
    }
    if (Float.isInfinite(fv)) {
      return Quote.sqlCastQuoted(fv < 0 ? "-inf" : "inf", "FLOAT32", quoteCfg);
    }
    return "CAST(" + formatGoG(fv, 32) + " AS FLOAT32)";
  }
}
