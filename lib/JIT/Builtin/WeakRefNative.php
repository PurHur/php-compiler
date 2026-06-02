<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** Native weakref registry symbols for JIT (#3667). */
final class WeakRefNative
{
    public static function registerDeclarations(Context $context): void
    {
        $void = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');

        foreach (
            [
                ['phpc_weakref_reset', $void, []],
                ['phpc_weakref_register_ref', $void, [$i8p, $i8p]],
                ['phpc_weakref_register_map', $void, [$i8p, $i8p, $i8p]],
                ['phpc_weakref_unregister_map', $void, [$i8p, $i8p, $i8p]],
                ['phpc_weakref_clear_object', $void, [$i8p]],
                ['phpc_weakref_clear_object_typed', $void, [$i8p, $i32]],
                ['phpc_weakref_format_object_key', $void, [$i8p, $i8p, $sizeT]],
            ] as [$name, $ret, $params]
        ) {
            $existing = $context->module->getNamedFunction($name);
            if (null !== $existing) {
                $context->registerFunction($name, $existing);

                continue;
            }
            $ft = $context->context->functionType($ret, false, ...$params);
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }
}
