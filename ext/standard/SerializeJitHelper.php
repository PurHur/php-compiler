<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * serialize() wire helpers for compiled JIT/AOT modules (#9440, php-in-PHP).
 *
 * SSOT for session wire values without VM Context; scalars/arrays/enums only.
 * php-src: ext/standard/var.c — php_var_serialize
 */
final class SerializeJitHelper
{
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
}
