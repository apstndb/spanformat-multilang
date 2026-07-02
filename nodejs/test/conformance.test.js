import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it } from 'node:test';

import {
  FORMAT_OPTION_MORE_VERBOSE,
  FORMAT_OPTION_NORMAL,
  FORMAT_OPTION_SIMPLE,
  FORMAT_OPTION_SIMPLEST,
  FORMAT_OPTION_VERBOSE,
  PreferredQuote,
  QuoteStrategy,
  formatType,
  formatTypeVerboseAnnotationOmit,
  formatTypeVerboseAnnotationPrimary,
  formatValue,
  literalFormatConfig,
  simpleFormatConfig,
  spannerCliFormatConfig,
} from '../lib/index.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const CONFORMANCE_PATH = path.resolve(__dirname, '../../testdata/conformance.json');

const TYPE_PRESETS = {
  simplest: FORMAT_OPTION_SIMPLEST,
  simple: FORMAT_OPTION_SIMPLE,
  normal: FORMAT_OPTION_NORMAL,
  verbose: FORMAT_OPTION_VERBOSE,
  more_verbose: FORMAT_OPTION_MORE_VERBOSE,
};

const QUOTE_POLICIES = {
  legacy_double: { strategy: QuoteStrategy.LEGACY, preferredQuote: PreferredQuote.DOUBLE },
  legacy_single: { strategy: QuoteStrategy.LEGACY, preferredQuote: PreferredQuote.SINGLE },
  always_double: { strategy: QuoteStrategy.ALWAYS, preferredQuote: PreferredQuote.DOUBLE },
  always_single: { strategy: QuoteStrategy.ALWAYS, preferredQuote: PreferredQuote.SINGLE },
  min_escape_double: { strategy: QuoteStrategy.MIN_ESCAPE, preferredQuote: PreferredQuote.DOUBLE },
  min_escape_single: { strategy: QuoteStrategy.MIN_ESCAPE, preferredQuote: PreferredQuote.SINGLE },
};

/** @returns {import('../lib/index.js')} */
function loadConformance() {
  return JSON.parse(readFileSync(CONFORMANCE_PATH, 'utf8'));
}

/**
 * @param {string} name
 * @param {Record<string, unknown>} typ
 */
function formatTypePreset(name, typ) {
  if (name === 'verbose_annotation_omit') {
    return formatTypeVerboseAnnotationOmit(typ);
  }
  if (name === 'verbose_annotation_primary') {
    return formatTypeVerboseAnnotationPrimary(typ);
  }
  return formatType(typ, TYPE_PRESETS[name]);
}

const conformance = loadConformance();

describe('type conformance', () => {
  for (const preset of [
    ...Object.keys(TYPE_PRESETS),
    'verbose_annotation_omit',
    'verbose_annotation_primary',
  ]) {
    it(`preset ${preset}`, () => {
      for (const testCase of conformance.type_cases) {
        const got = formatTypePreset(preset, testCase.type);
        const want = testCase.expected[preset];
        assert.equal(
          got,
          want,
          `type case ${testCase.name} preset ${preset}: got ${JSON.stringify(got)} want ${JSON.stringify(want)}`,
        );
      }
    });
  }
});

describe('value conformance', () => {
  for (const preset of ['simple', 'literal', 'spanner_cli']) {
    it(`preset ${preset}`, () => {
      const config = preset === 'simple'
        ? simpleFormatConfig()
        : preset === 'literal'
          ? literalFormatConfig()
          : spannerCliFormatConfig();

      for (const testCase of conformance.value_cases) {
        const got = formatValue(testCase.type, testCase.value, config);
        const want = testCase.expected[preset];
        assert.equal(
          got,
          want,
          `value case ${testCase.name} preset ${preset}: got ${JSON.stringify(got)} want ${JSON.stringify(want)}`,
        );
      }
    });
  }
});

describe('literal quote conformance', () => {
  for (const policyName of Object.keys(QUOTE_POLICIES)) {
    it(`policy ${policyName}`, () => {
      const config = literalFormatConfig(QUOTE_POLICIES[policyName]);
      for (const testCase of conformance.value_cases) {
        const got = formatValue(testCase.type, testCase.value, config);
        const want = testCase.expected.literal_quotes[policyName];
        assert.equal(
          got,
          want,
          `value case ${testCase.name} quote ${policyName}: got ${JSON.stringify(got)} want ${JSON.stringify(want)}`,
        );
      }
    });
  }
});
