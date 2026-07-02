/** Go strconv.FormatFloat(v, 'g', -1, bits) compatible formatting. */

import { sqlCastQuoted } from './quote.js';

const E_RE = /^(\d)(?:\.(\d+))?e([+-])(\d+)$/;

/**
 * @param {number} v
 * @returns {number}
 */
export function narrowFloat32(v) {
  const buffer = new ArrayBuffer(4);
  const view = new DataView(buffer);
  view.setFloat32(0, v);
  return view.getFloat32(0);
}

/**
 * @param {number} v
 * @returns {number}
 */
function packFloat32(v) {
  const buffer = new ArrayBuffer(4);
  const view = new DataView(buffer);
  view.setFloat32(0, v);
  return view.getFloat32(0);
}

/**
 * @param {string} s
 * @param {number} original
 * @param {number} bits
 */
function roundTrips(s, original, bits) {
  const parsed = Number(s);
  if (Number.isNaN(parsed)) {
    return Number.isNaN(original);
  }
  if (!Number.isFinite(parsed)) {
    return !Number.isFinite(original) && Math.sign(parsed) === Math.sign(original);
  }
  if (bits === 32) {
    return packFloat32(parsed) === packFloat32(original);
  }
  return parsed === original;
}

/**
 * @param {number} exp
 */
function fmtExponent(exp) {
  if (exp >= 0) {
    return `e+${String(exp).padStart(2, '0')}`;
  }
  const ae = Math.abs(exp);
  if (ae < 10) {
    return `e-${String(ae).padStart(2, '0')}`;
  }
  return `e${exp}`;
}

/**
 * @param {string} es
 */
function peToGoG(es) {
  const m = E_RE.exec(es);
  if (!m) {
    throw new Error(`unexpected e-format: ${es}`);
  }
  const d1 = m[1];
  let rest = m[2] ?? '';
  const exp = Number(`${m[3]}${m[4]}`);
  let sig = d1 + rest.replace(/0+$/, '');
  if (!sig) {
    sig = '0';
  }
  const ndigits = sig.length;

  if (exp >= -4 && exp < 6) {
    const decPos = 1 + exp;
    let s;
    if (decPos <= 0) {
      s = '0.' + '0'.repeat(-decPos) + sig;
    } else if (decPos >= ndigits) {
      s = sig + '0'.repeat(decPos - ndigits);
    } else {
      s = sig.slice(0, decPos) + '.' + sig.slice(decPos);
    }
    if (s.includes('.')) {
      s = s.replace(/0+$/, '').replace(/\.$/, '');
    }
    return s;
  }

  const body = ndigits === 1 ? sig : sig[0] + '.' + sig.slice(1);
  return body + fmtExponent(exp);
}

/**
 * Match Go strconv.FormatFloat(v, 'g', -1, bits).
 * @param {number} v
 * @param {32 | 64} [bits]
 */
export function formatGoG(v, bits = 64) {
  if (bits === 32) {
    v = narrowFloat32(v);
  }

  if (Number.isNaN(v)) {
    return 'NaN';
  }
  if (!Number.isFinite(v)) {
    return v < 0 ? '-Inf' : '+Inf';
  }
  if (v === 0 && Object.is(v, -0)) {
    return '-0';
  }

  const negative = v < 0;
  const av = negative ? -v : v;
  const maxP = bits === 64 ? 16 : 8;
  const target = bits === 64 ? v : narrowFloat32(v);

  /** @type {string | null} */
  let best = null;
  for (let p = 0; p <= maxP; p++) {
    const es = av.toExponential(p).replace('E', 'e');
    const g = peToGoG(es);
    const candidate = (negative ? '-' : '') + g;
    if (roundTrips(candidate, target, bits)) {
      if (best == null || candidate.length < best.length) {
        best = candidate;
      }
    }
  }

  if (best == null) {
    best = (negative ? '-' : '') + String(av);
  }
  return best;
}

/**
 * @param {number} v
 * @returns {bigint}
 */
function getDoubleBits(v) {
  const buffer = new ArrayBuffer(8);
  const view = new DataView(buffer);
  view.setFloat64(0, v);
  return view.getBigUint64(0);
}

/**
 * Exact rational value of an IEEE-754 double (matches Java exactBigDecimal).
 * @param {number} v
 * @returns {{ negative: boolean, numerator: bigint, denominator: bigint }}
 */
function exactRational(v) {
  const bits = getDoubleBits(v);
  const negative = (bits >> 63n) !== 0n;
  const biasedExp = Number((bits >> 52n) & 0x7ffn);
  const fraction = bits & 0x000fffffffffffffn;

  if (biasedExp === 0x7ff) {
    throw new Error('NaN or infinite');
  }

  /** @type {bigint} */
  let significand;
  /** @type {number} */
  let exponent;
  if (biasedExp === 0) {
    if (fraction === 0n) {
      return { negative, numerator: 0n, denominator: 1n };
    }
    exponent = -1022 - 52;
    significand = fraction;
    while (significand.toString(2).length <= 52) {
      significand <<= 1n;
      exponent--;
    }
  } else {
    exponent = biasedExp - 1023 - 52;
    significand = fraction | (1n << 52n);
  }

  /** @type {bigint} */
  let numerator;
  /** @type {bigint} */
  let denominator = 1n;
  if (exponent >= 0) {
    numerator = significand << BigInt(exponent);
  } else {
    numerator = significand;
    denominator = 1n << BigInt(-exponent);
  }
  return { negative, numerator, denominator };
}

/**
 * @param {bigint} num
 * @param {bigint} den
 * @param {number} scale
 */
function roundHalfEven(num, den, scale) {
  const factor = 10n ** BigInt(scale);
  const scaledNum = num * factor;
  let q = scaledNum / den;
  const r = scaledNum % den;
  const twiceR = 2n * r;
  if (twiceR > den) {
    q += 1n;
  } else if (twiceR === den && q % 2n === 1n) {
    q += 1n;
  }
  return q;
}

/**
 * @param {bigint} q
 * @param {number} scale
 * @param {boolean} negative
 */
function formatScaledInteger(q, scale, negative) {
  if (q === 0n) {
    if (scale === 0) {
      return negative ? '-0' : '0';
    }
    return negative ? `-0.${'0'.repeat(scale)}` : `0.${'0'.repeat(scale)}`;
  }
  const sign = negative ? '-' : '';
  const s = q.toString();
  if (scale === 0) {
    return sign + s;
  }
  if (s.length <= scale) {
    return sign + '0.' + s.padStart(scale, '0');
  }
  return sign + s.slice(0, -scale) + '.' + s.slice(-scale);
}

/**
 * C-style %.Nf with IEEE round-half-even (matches C printf / Java BigDecimal).
 * @param {number} v
 * @param {number} precision
 */
function formatFixed(v, precision) {
  if (Object.is(v, -0)) {
    return precision === 0 ? '-0' : `-0.${'0'.repeat(precision)}`;
  }
  const { negative, numerator, denominator } = exactRational(v);
  const q = roundHalfEven(numerator, denominator, precision);
  return formatScaledInteger(q, precision, negative);
}

/**
 * Match spanner-cli float rendering.
 * @param {number} v
 * @param {32 | 64} [bits]
 */
export function formatSpannerCliFloat(v, bits = 64) {
  if (bits === 32) {
    v = narrowFloat32(v);
  }
  if (Number.isNaN(v)) {
    return 'NaN';
  }
  if (!Number.isFinite(v)) {
    return v < 0 ? '-Inf' : '+Inf';
  }
  if (v === Math.trunc(v)) {
    return formatFixed(v, 0);
  }
  return formatFixed(v, 6);
}

/**
 * @param {number} v
 * @param {import('./quote.js').LiteralQuoteConfig} quoteCfg
 */
export function float64ToLiteral(v, quoteCfg) {
  if (Number.isNaN(v)) {
    return sqlCastQuoted('nan', 'FLOAT64', quoteCfg);
  }
  if (!Number.isFinite(v)) {
    return sqlCastQuoted(v < 0 ? '-inf' : 'inf', 'FLOAT64', quoteCfg);
  }
  let s = formatGoG(v, 64);
  if (!/[.eE]/.test(s)) {
    s += '.0';
  }
  return s;
}

/**
 * @param {number} v
 * @param {import('./quote.js').LiteralQuoteConfig} quoteCfg
 */
export function float32ToLiteral(v, quoteCfg) {
  const fv = narrowFloat32(v);
  if (Number.isNaN(fv)) {
    return sqlCastQuoted('nan', 'FLOAT32', quoteCfg);
  }
  if (!Number.isFinite(fv)) {
    return sqlCastQuoted(fv < 0 ? '-inf' : 'inf', 'FLOAT32', quoteCfg);
  }
  return `CAST(${formatGoG(fv, 32)} AS FLOAT32)`;
}
