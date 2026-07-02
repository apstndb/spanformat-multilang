/** GoogleSQL literal quoting and escaping. */

export const QuoteStrategy = Object.freeze({
  LEGACY: 0,
  ALWAYS: 1,
  MIN_ESCAPE: 2,
});

export const PreferredQuote = Object.freeze({
  DOUBLE: 0,
  SINGLE: 1,
});

/**
 * @typedef {Object} LiteralQuoteConfig
 * @property {number} [strategy]
 * @property {number} [preferredQuote]
 */

/** @type {LiteralQuoteConfig} */
export const DEFAULT_LITERAL_QUOTE = Object.freeze({
  strategy: QuoteStrategy.LEGACY,
  preferredQuote: PreferredQuote.DOUBLE,
});

/** @param {LiteralQuoteConfig | null | undefined} cfg */
export function normalizeLiteralQuote(cfg) {
  const input = cfg ?? DEFAULT_LITERAL_QUOTE;
  let strategy = input.strategy ?? QuoteStrategy.LEGACY;
  if (strategy !== QuoteStrategy.LEGACY &&
      strategy !== QuoteStrategy.ALWAYS &&
      strategy !== QuoteStrategy.MIN_ESCAPE) {
    strategy = QuoteStrategy.LEGACY;
  }
  let preferredQuote = input.preferredQuote ?? PreferredQuote.DOUBLE;
  if (preferredQuote !== PreferredQuote.DOUBLE &&
      preferredQuote !== PreferredQuote.SINGLE) {
    preferredQuote = PreferredQuote.DOUBLE;
  }
  return { strategy, preferredQuote };
}

const PRINTABLE_RE = /^[\p{L}\p{M}\p{N}\p{P}\p{S} ]$/u;

/**
 * @param {number} r
 */
function isPrintable(r) {
  if (r === 0x20) {
    return true;
  }
  return PRINTABLE_RE.test(String.fromCodePoint(r));
}

/**
 * @param {string | Uint8Array} payload
 * @param {LiteralQuoteConfig} cfg
 */
export function quoteCharForPayload(payload, cfg) {
  const normalized = normalizeLiteralQuote(cfg);
  /** @type {Uint8Array} */
  let data;
  if (typeof payload === 'string') {
    data = new TextEncoder().encode(payload);
  } else {
    data = payload;
  }

  if (normalized.strategy === QuoteStrategy.ALWAYS) {
    return normalized.preferredQuote === PreferredQuote.SINGLE ? "'" : '"';
  }

  const pref = normalized.preferredQuote === PreferredQuote.SINGLE ? 39 : 34;
  const other = pref === 39 ? 34 : 39;

  if (normalized.strategy === QuoteStrategy.MIN_ESCAPE) {
    let singleCount = 0;
    let doubleCount = 0;
    for (const b of data) {
      if (b === 39) {
        singleCount++;
      } else if (b === 34) {
        doubleCount++;
      }
    }
    if (singleCount < doubleCount) {
      return "'";
    }
    if (doubleCount < singleCount) {
      return '"';
    }
    return String.fromCharCode(pref);
  }

  let hasPref = false;
  for (const b of data) {
    if (b === other) {
      return String.fromCharCode(pref);
    }
    if (b === pref) {
      hasPref = true;
    }
  }
  if (hasPref) {
    return String.fromCharCode(other);
  }
  return String.fromCharCode(pref);
}

/**
 * @param {number} r
 * @param {boolean} isString
 * @param {string} quote
 */
export function escapeRune(r, isString, quote) {
  const q = quote ? quote.charCodeAt(0) : -1;
  if (r === 92 || (quote && r === q)) {
    return '\\' + String.fromCharCode(r);
  }
  if (isString && r === 10) {
    return '\\n';
  }
  if (isString && r === 13) {
    return '\\r';
  }
  if (isString && r === 9) {
    return '\\t';
  }
  if (isString && isPrintable(r)) {
    return String.fromCodePoint(r);
  }
  if (r >= 0x20 && r <= 0x7E) {
    return String.fromCharCode(r);
  }
  if (r < 0x100) {
    return '\\x' + r.toString(16).padStart(2, '0');
  }
  if (r > 0xFFFF) {
    return '\\U' + r.toString(16).padStart(8, '0');
  }
  return '\\u' + r.toString(16).padStart(4, '0');
}

/**
 * @param {string} s
 * @param {LiteralQuoteConfig} cfg
 */
export function toStringLiteral(s, cfg) {
  const quote = quoteCharForPayload(s, cfg);
  const parts = [quote];
  for (const ch of s) {
    parts.push(escapeRune(ch.codePointAt(0) ?? 0, true, quote));
  }
  parts.push(quote);
  return parts.join('');
}

/**
 * @param {Uint8Array} data
 * @param {LiteralQuoteConfig} cfg
 */
export function toBytesLiteral(data, cfg) {
  const quote = quoteCharForPayload(data, cfg);
  const parts = ['b', quote];
  for (const b of data) {
    parts.push(escapeRune(b, false, quote));
  }
  parts.push(quote);
  return parts.join('');
}

/**
 * @param {string} payload
 * @param {string} castType
 * @param {LiteralQuoteConfig} cfg
 */
export function sqlCastQuoted(payload, castType, cfg) {
  const lit = toStringLiteral(payload, cfg);
  return `CAST(${lit} AS ${castType})`;
}
