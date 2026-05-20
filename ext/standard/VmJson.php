<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * Export VM values to PHP scalars/arrays for json_encode() delegation.
 */
final class VmJson
{
    public static function export(Variable $v): mixed
    {
        $v = $v->resolveIndirect();
        switch ($v->type) {
            case Variable::TYPE_NULL:
                return null;
            case Variable::TYPE_INTEGER:
                return $v->toInt();
            case Variable::TYPE_FLOAT:
                return $v->toFloat();
            case Variable::TYPE_BOOLEAN:
                return $v->toBool();
            case Variable::TYPE_STRING:
                return $v->toString();
            case Variable::TYPE_ARRAY:
                $out = [];
                foreach ($v->toArray()->iterateKeyed(true) as [$key, $value]) {
                    $k = $key->resolveIndirect();
                    if (Variable::TYPE_STRING !== $k->type) {
                        throw new \LogicException(
                            'json_encode() only supports string keys in this compiler build'
                        );
                    }
                    $out[$k->toString()] = self::export($value);
                }

                return $out;
            default:
                throw new \LogicException(
                    'json_encode() value type not supported in this compiler build'
                );
        }
    }
}
