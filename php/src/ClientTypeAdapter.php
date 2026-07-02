<?php

declare(strict_types=1);

namespace Apstndb\SpanValue;

final class ClientTypeAdapter
{
    /**
     * Adapt a client wrapper type to a wire `Type` shape (protojson-compatible keys).
     *
     * @return array<string, mixed>
     */
    public static function adapt(mixed $clientType): array
    {
        if ($clientType === null) {
            return ['code' => 'TYPE_CODE_UNSPECIFIED'];
        }

        $code = Proto::typeCode($clientType);
        $name = TypeCode::nameOf($code);
        /** @var array<string, mixed> $wire */
        $wire = ['code' => $name ?? $code];

        $ann = Proto::typeAnnotation($clientType);
        if ($ann !== TypeAnnotationCode::TYPE_ANNOTATION_CODE_UNSPECIFIED->value) {
            $annName = TypeAnnotationCode::nameOf($ann);
            if ($annName !== null) {
                $wire['typeAnnotation'] = $annName;
            }
        }

        $fqn = Proto::protoTypeFqn($clientType);
        if ($fqn !== '') {
            $wire['protoTypeFqn'] = $fqn;
        }

        $elem = Proto::arrayElementType($clientType);
        if ($elem !== null) {
            $wire['arrayElementType'] = self::adapt($elem);
        }

        if (Proto::structType($clientType) !== null) {
            $fields = [];
            foreach (Proto::structFields($clientType) as $field) {
                $fieldName = Proto::fieldName($field);
                /** @var array<string, mixed> $out */
                $out = [];
                if ($fieldName !== '') {
                    $out['name'] = $fieldName;
                }
                $out['type'] = self::adapt(Proto::fieldType($field));
                $fields[] = $out;
            }
            $wire['structType'] = ['fields' => $fields];
        }

        return $wire;
    }
}
