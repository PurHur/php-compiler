<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * Export VM values to PHP arrays for http_build_query() delegation.
 */
final class VmHttpBuildQuery
{
    public const ENCODING_RFC1738 = 1;
    public const ENCODING_RFC3986 = 2;

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
                    if (Variable::TYPE_STRING === $k->type) {
                        $out[$k->toString()] = self::export($value);
                    } elseif (Variable::TYPE_INTEGER === $k->type) {
                        $out[$k->toInt()] = self::export($value);
                    } else {
                        throw new \LogicException(
                            'http_build_query() only supports string or integer keys in this compiler build'
                        );
                    }
                }

                return $out;
            default:
                throw new \LogicException(
                    'http_build_query() value type not supported in this compiler build'
                );
        }
    }
}
