<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** Declare native reflection/attribute registry symbols for JIT/AOT (#1936). */
final class ReflectionNative
{
    public static function registerDeclarations(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach (
            [
                ['phpc_attr_class_count', $sizeT, [$i8p]],
                ['phpc_attr_class_name_at', $i8p, [$i8p, $sizeT]],
                ['phpc_attr_method_count', $sizeT, [$i8p, $i8p]],
                ['phpc_attr_method_name_at', $i8p, [$i8p, $i8p, $sizeT]],
                ['phpc_attr_class_args_hashtable', $context->getTypeFromString('__hashtable__*'), [$i8p, $sizeT]],
                ['phpc_attr_class_string_arg', $i8p, [$i8p, $sizeT, $sizeT]],
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
