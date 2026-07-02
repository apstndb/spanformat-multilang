# spanvalue (Node.js)

Format Cloud Spanner `google.spanner.v1.Type` values and column wire values
(`google.protobuf.Value`) as human-readable text, re-parseable GoogleSQL
literals, or byte-for-byte [spanner-cli](https://github.com/cloudspannerecosystem/spanner-cli)
compatible output.

Zero npm dependencies. Node.js 18+. ESM.

## Input model

- **Protojson plain objects** — primary conformance path
- **Duck-typed protobuf objects** — structural compatibility with
  `@google-cloud/spanner` protos (`code`, `structType`, `WhichOneof('kind')`,
  etc.) when shapes match; no hard dependency on the client package

Values accept plain JS scalars, arrays for ARRAY/STRUCT wire shorthand, and
wrapped wire objects (`stringValue`, `listValue`, ...).

High-level client row types are not accepted — convert to wire
`(Type, Value)` first.

## Quick start

```javascript
import { formatValue, literalFormatConfig } from '@apstndb/spanvalue';

const typ = {
  code: 'STRUCT',
  structType: {
    fields: [
      { name: 'n', type: { code: 'INT64' } },
      { name: 's', type: { code: 'STRING' } },
    ],
  },
};
const value = ['1', 'hello'];

const config = literalFormatConfig();
console.log(formatValue(typ, value, config));
// STRUCT<n INT64, s STRING>(1, "hello")
```

## Tests

```bash
cd nodejs && npm test
```

Conformance cases load `../testdata/conformance.json`.

## License

MIT
