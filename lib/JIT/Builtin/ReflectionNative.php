<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** Declare native reflection/attribute registry symbols for JIT/AOT (#1936). */
final class ReflectionNative
{
    public static function registerDeclarations(Context $context): void
    {
        $void = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $objPtr = $context->getTypeFromString('__object__*');
        $sizeTPtr = $context->getTypeFromString('size_t*');

        foreach (
            [
                ['phpc_reflect_set_class', $void, [$i8p, $i8p, $sizeT]],
                ['phpc_reflect_get_class_name', $i8p, [$i8p, $sizeTPtr]],
                ['phpc_reflect_set_method', $void, [$i8p, $i8p, $sizeT, $i8p, $sizeT]],
                ['phpc_reflect_get_method_class', $i8p, [$i8p, $sizeTPtr]],
                ['phpc_reflect_get_method_name', $i8p, [$i8p, $sizeTPtr]],
                ['phpc_reflect_set_attr_name', $void, [$i8p, $i8p, $sizeT]],
                ['phpc_reflect_get_attr_name', $i8p, [$i8p, $sizeTPtr]],
                ['phpc_attr_class_count', $sizeT, [$i8p]],
                ['phpc_attr_class_name_at', $i8p, [$i8p, $sizeT]],
                ['phpc_attr_method_count', $sizeT, [$i8p, $i8p]],
                ['phpc_attr_method_name_at', $i8p, [$i8p, $i8p, $sizeT]],
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
