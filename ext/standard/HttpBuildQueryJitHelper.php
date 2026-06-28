<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * http_build_query() for compiled JIT/AOT modules (#9443, php-in-PHP).
 *
 * SSOT: {@see VmHttpBuildQuery::build()}
 * php-src: ext/standard/http.c — php_url_encode_hash_ex, http_build_query
 */
final class HttpBuildQueryJitHelper
{
    public static function build(
        HashTable $data,
        string $prefix,
        string $separator,
        int $encoding
    ): string {
        return VmHttpBuildQuery::build(
            self::exportTable($data),
            $prefix,
            $separator,
            $encoding
        );
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function exportTable(HashTable $ht): array
    {
        $out = [];
        foreach ($ht->exportKeyValuePairs(true) as [$keyVar, $valVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING === $key->type) {
                $out[$key->toString()] = self::exportValue($valVar);
            } elseif (Variable::TYPE_INTEGER === $key->type) {
                $out[$key->toInt()] = self::exportValue($valVar);
            }
        }

        return $out;
    }

    private static function exportValue(Variable $v): mixed
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
                return self::exportTable($v->toArray());
            default:
                return '';
        }
    }
}
