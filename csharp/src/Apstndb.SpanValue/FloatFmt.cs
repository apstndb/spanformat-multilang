using System.Globalization;
using System.Text.RegularExpressions;

namespace Apstndb.SpanValue;

public static class FloatFmt
{
    private static readonly Regex ERe = new(@"^(\d)(?:\.(\d+))?e([+-])(\d+)$", RegexOptions.Compiled);

    public static float NarrowFloat32(double v) =>
        BitConverter.Int32BitsToSingle(BitConverter.SingleToInt32Bits((float)v));

    private static float PackFloat32(double v) => NarrowFloat32(v);

    private static bool RoundTrips(string s, double original, int bits)
    {
        if (!double.TryParse(s, NumberStyles.Float, CultureInfo.InvariantCulture, out var parsed))
            return false;

        if (double.IsNaN(original))
            return double.IsNaN(parsed);

        if (double.IsInfinity(original))
            return double.IsInfinity(parsed) && (parsed > 0) == (original > 0);

        if (bits == 32)
            return PackFloat32(parsed) == PackFloat32(original);

        return parsed == original;
    }

    private static string FmtExponent(int exp)
    {
        if (exp >= 0)
            return $"e+{exp:D2}";
        var ae = Math.Abs(exp);
        return ae < 10 ? $"e-{ae:D2}" : $"e{exp}";
    }

    private static string PeToGoG(string es)
    {
        var m = ERe.Match(es);
        if (!m.Success)
            throw new FormatException($"unexpected e-format: {es}");

        var d1 = m.Groups[1].Value;
        var rest = m.Groups[2].Success ? m.Groups[2].Value : "";
        var esign = m.Groups[3].Value;
        var eexp = m.Groups[4].Value;
        var exp = int.Parse(esign + eexp, CultureInfo.InvariantCulture);

        rest = rest.TrimEnd('0');
        var sig = d1 + rest;
        if (sig.Length == 0)
            sig = "0";
        var ndigits = sig.Length;

        if (exp is >= -4 and < 6)
        {
            var decPos = 1 + exp;
            string s;
            if (decPos <= 0)
                s = "0." + new string('0', -decPos) + sig;
            else if (decPos >= ndigits)
                s = sig + new string('0', decPos - ndigits);
            else
                s = sig[..decPos] + "." + sig[decPos..];

            if (s.Contains('.'))
                s = s.TrimEnd('0').TrimEnd('.');

            return s;
        }

        var body = ndigits == 1 ? sig : sig[0] + "." + sig[1..];
        return body + FmtExponent(exp);
    }

    public static string FormatGoG(double v, int bits = 64)
    {
        if (bits == 32)
            v = NarrowFloat32(v);

        if (double.IsNaN(v))
            return "NaN";
        if (double.IsPositiveInfinity(v))
            return "+Inf";
        if (double.IsNegativeInfinity(v))
            return "-Inf";
        if (v == 0.0 && double.IsNegative(v))
            return "-0";

        var negative = v < 0;
        var av = negative ? -v : v;
        var maxP = bits == 64 ? 16 : 8;
        var target = bits == 64 ? v : NarrowFloat32(v);

        string? best = null;
        for (var p = 0; p <= maxP; p++)
        {
            var es = av.ToString($"e{p}", CultureInfo.InvariantCulture);
            var g = PeToGoG(es);
            var candidate = (negative ? "-" : "") + g;
            if (RoundTrips(candidate, target, bits))
            {
                if (best is null || candidate.Length < best.Length)
                    best = candidate;
            }
        }

        return best ?? (negative ? "-" : "") + av.ToString(CultureInfo.InvariantCulture);
    }

    public static string FormatSpannerCliFloat(double v, int bits = 64)
    {
        if (bits == 32)
            v = NarrowFloat32(v);

        if (double.IsNaN(v))
            return "NaN";
        if (double.IsPositiveInfinity(v))
            return "+Inf";
        if (double.IsNegativeInfinity(v))
            return "-Inf";

        if (v == Math.Truncate(v))
            return v.ToString("F0", CultureInfo.InvariantCulture);

        return v.ToString("F6", CultureInfo.InvariantCulture);
    }

    public static string Float64ToLiteral(double v, LiteralQuoteConfig quoteCfg)
    {
        if (double.IsNaN(v))
            return Quote.SqlCastQuoted("nan", "FLOAT64", quoteCfg);
        if (double.IsInfinity(v))
            return Quote.SqlCastQuoted(v < 0 ? "-inf" : "inf", "FLOAT64", quoteCfg);

        var s = FormatGoG(v, 64);
        if (!s.Contains('.') && !s.Contains('e') && !s.Contains('E'))
            s += ".0";
        return s;
    }

    public static string Float32ToLiteral(double v, LiteralQuoteConfig quoteCfg)
    {
        var fv = NarrowFloat32(v);
        if (float.IsNaN((float)fv))
            return Quote.SqlCastQuoted("nan", "FLOAT32", quoteCfg);
        if (float.IsInfinity((float)fv))
            return Quote.SqlCastQuoted(fv < 0 ? "-inf" : "inf", "FLOAT32", quoteCfg);

        return $"CAST({FormatGoG(fv, 32)} AS FLOAT32)";
    }
}
