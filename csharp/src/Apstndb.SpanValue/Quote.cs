using System.Globalization;
using System.Text;

namespace Apstndb.SpanValue;

public enum QuoteStrategy
{
    Legacy = 0,
    Always = 1,
    MinEscape = 2,
}

public enum PreferredQuote
{
    Double = 0,
    Single = 1,
}

public sealed record LiteralQuoteConfig(
    QuoteStrategy Strategy = QuoteStrategy.Legacy,
    PreferredQuote PreferredQuote = PreferredQuote.Double);

public static class Quote
{
    public static LiteralQuoteConfig NormalizeLiteralQuote(LiteralQuoteConfig cfg)
    {
        var strategy = cfg.Strategy is QuoteStrategy.Legacy or QuoteStrategy.Always or QuoteStrategy.MinEscape
            ? cfg.Strategy
            : QuoteStrategy.Legacy;
        var preferred = cfg.PreferredQuote is PreferredQuote.Double or PreferredQuote.Single
            ? cfg.PreferredQuote
            : PreferredQuote.Double;
        return cfg with { Strategy = strategy, PreferredQuote = preferred };
    }

    public static char QuoteCharForPayload(ReadOnlySpan<byte> data, LiteralQuoteConfig cfg)
    {
        cfg = NormalizeLiteralQuote(cfg);

        if (cfg.Strategy == QuoteStrategy.Always)
            return cfg.PreferredQuote == PreferredQuote.Single ? '\'' : '"';

        var pref = cfg.PreferredQuote == PreferredQuote.Single ? (byte)'\'' : (byte)'"';
        var other = pref == (byte)'\'' ? (byte)'"' : (byte)'\'';

        if (cfg.Strategy == QuoteStrategy.MinEscape)
        {
            var singleCount = CountByte(data, (byte)'\'');
            var doubleCount = CountByte(data, (byte)'"');
            if (singleCount < doubleCount)
                return '\'';
            if (doubleCount < singleCount)
                return '"';
            return (char)pref;
        }

        var hasPref = false;
        foreach (var b in data)
        {
            if (b == other)
                return (char)pref;
            if (b == pref)
                hasPref = true;
        }

        return hasPref ? (char)other : (char)pref;
    }

    public static char QuoteCharForPayload(string payload, LiteralQuoteConfig cfg) =>
        QuoteCharForPayload(Encoding.UTF8.GetBytes(payload), cfg);

    public static string EscapeRune(int r, bool isString, char quote)
    {
        var q = quote == '\0' ? -1 : quote;

        if (r == '\\' || (q >= 0 && r == q))
            return "\\" + (char)r;

        if (isString && r == '\n')
            return "\\n";
        if (isString && r == '\r')
            return "\\r";
        if (isString && r == '\t')
            return "\\t";

        if (isString && IsPrintable(r))
            return new Rune(r).ToString();

        if (r is >= 0x20 and <= 0x7E)
            return ((char)r).ToString();

        if (r < 0x100)
            return $"\\x{r:x2}";

        if (r > 0xFFFF)
            return $"\\U{r:x8}";

        return $"\\u{r:x4}";
    }

    public static string ToStringLiteral(string s, LiteralQuoteConfig cfg)
    {
        var quote = QuoteCharForPayload(s, cfg);
        var sb = new StringBuilder(s.Length + 2);
        sb.Append(quote);
        foreach (var rune in s.EnumerateRunes())
            sb.Append(EscapeRune(rune.Value, isString: true, quote));
        sb.Append(quote);
        return sb.ToString();
    }

    public static string ToBytesLiteral(ReadOnlySpan<byte> data, LiteralQuoteConfig cfg)
    {
        var quote = QuoteCharForPayload(data, cfg);
        var sb = new StringBuilder(data.Length + 3);
        sb.Append('b').Append(quote);
        foreach (var b in data)
            sb.Append(EscapeRune(b, isString: false, quote));
        sb.Append(quote);
        return sb.ToString();
    }

    public static string SqlCastQuoted(string payload, string castType, LiteralQuoteConfig cfg)
    {
        var lit = ToStringLiteral(payload, cfg);
        return $"CAST({lit} AS {castType})";
    }

    private static int CountByte(ReadOnlySpan<byte> data, byte b)
    {
        var count = 0;
        foreach (var x in data)
        {
            if (x == b)
                count++;
        }

        return count;
    }

    private static bool IsPrintable(int r)
    {
        if (r == 0x20)
            return true;

        if (!Rune.IsValid(r))
            return false;

        var cat = r <= char.MaxValue
            ? char.GetUnicodeCategory((char)r)
            : CharUnicodeInfo.GetUnicodeCategory(char.ConvertFromUtf32(r), 0);

        return cat is UnicodeCategory.UppercaseLetter
            or UnicodeCategory.LowercaseLetter
            or UnicodeCategory.TitlecaseLetter
            or UnicodeCategory.ModifierLetter
            or UnicodeCategory.OtherLetter
            or UnicodeCategory.NonSpacingMark
            or UnicodeCategory.SpacingCombiningMark
            or UnicodeCategory.EnclosingMark
            or UnicodeCategory.DecimalDigitNumber
            or UnicodeCategory.LetterNumber
            or UnicodeCategory.OtherNumber
            or UnicodeCategory.ConnectorPunctuation
            or UnicodeCategory.DashPunctuation
            or UnicodeCategory.OpenPunctuation
            or UnicodeCategory.ClosePunctuation
            or UnicodeCategory.InitialQuotePunctuation
            or UnicodeCategory.FinalQuotePunctuation
            or UnicodeCategory.OtherPunctuation
            or UnicodeCategory.MathSymbol
            or UnicodeCategory.CurrencySymbol
            or UnicodeCategory.ModifierSymbol
            or UnicodeCategory.OtherSymbol;
    }
}
