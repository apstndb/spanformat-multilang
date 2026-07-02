namespace Apstndb.SpanValue;

public static class BytesFmt
{
    public static byte[] DecodeBase64Wire(string wire)
    {
        if (wire.Length == 0)
            return Array.Empty<byte>();
        return Convert.FromBase64String(wire);
    }

    private static bool IsReadableAscii(ReadOnlySpan<byte> data)
    {
        foreach (var c in data)
        {
            if (c == (byte)'\\' || c < 0x20 || c > 0x7E)
                return false;
        }

        return true;
    }

    public static string ReadableBytesString(ReadOnlySpan<byte> data)
    {
        if (data.Length == 0)
            return "";

        if (IsReadableAscii(data))
            return System.Text.Encoding.ASCII.GetString(data);

        var parts = new List<string>(data.Length);
        foreach (var b in data)
            parts.Add(Quote.EscapeRune(b, isString: false, quote: '\0'));
        return string.Concat(parts);
    }

    public static string ReadableStringFromBase64Wire(string wire) =>
        ReadableBytesString(DecodeBase64Wire(wire));
}
