<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * serialize() wire helpers for compiled JIT/AOT modules (#9440, #9180, php-in-PHP).
 *
 * SSOT: {@see VmSerialize} via active VM context; session wire uses scalar/array subset.
 * php-src: ext/standard/var.c — php_var_serialize
 */
final class SerializeJitHelper
{
    public static function encodeValue(Variable $value): string
    {
        $ctx = self::requireActiveContext();
        return VmSerialize::serializeValue($ctx, $value->resolveIndirect());
    }

    public static function encodeHashtable(HashTable $ht): string
    {
        $var = new Variable(Variable::TYPE_ARRAY);
        $var->array($ht);

        return self::encodeValue($var);
    }

    public static function serializeSessionWireValue(Variable $value): ?string
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT === $value->type) {
            return null;
        }
        try {
            return VmSerializeFormat::encodeExported(self::exportJit($value));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<mixed>|bool|float|int|null|string
     */
    private static function exportJit(Variable $value): mixed
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ARRAY === $value->type) {
            $out = [];
            foreach ($value->toArray()->iterateKeyed(true) as [$key, $elem]) {
                $k = $key->resolveIndirect();
                if (Variable::TYPE_STRING === $k->type) {
                    $out[$k->toString()] = self::exportJit($elem);
                } elseif (Variable::TYPE_INTEGER === $k->type) {
                    $out[$k->toInt()] = self::exportJit($elem);
                } else {
                    throw new \LogicException(
                        'serialize() only supports string or integer keys in this compiler build'
                    );
                }
            }

            return $out;
        }

        return VmJson::export($value);
    }

    private static function requireActiveContext(): Context
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException('serialize() JIT helper requires active VM context (#9180)');
        }

        return $ctx;
    }
}
