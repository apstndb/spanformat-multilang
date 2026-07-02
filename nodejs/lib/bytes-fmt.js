/** BYTES/PROTO readable formatting. */

import { escapeRune } from './quote.js';

/**
 * @param {string} wire
 * @returns {Uint8Array}
 */
export function decodeBase64Wire(wire) {
  if (wire === '') {
    return new Uint8Array(0);
  }
  return new Uint8Array(Buffer.from(wire, 'base64'));
}

/**
 * @param {Uint8Array} data
 */
function isReadableAscii(data) {
  for (const c of data) {
    if (c === 92 || c < 0x20 || c > 0x7E) {
      return false;
    }
  }
  return true;
}

/**
 * @param {Uint8Array} data
 */
export function readableBytesString(data) {
  if (data.length === 0) {
    return '';
  }
  if (isReadableAscii(data)) {
    return Buffer.from(data).toString('ascii');
  }
  const parts = [];
  for (const b of data) {
    parts.push(escapeRune(b, false, ''));
  }
  return parts.join('');
}

/**
 * @param {string} wire
 */
export function readableStringFromBase64Wire(wire) {
  return readableBytesString(decodeBase64Wire(wire));
}
