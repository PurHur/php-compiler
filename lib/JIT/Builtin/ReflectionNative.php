<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\Context;

/** Declare LLVM attribute lookup symbols lowered from PHP tables (#1936, #6922). */
final class ReflectionNative
{
    public static function registerDeclarations(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach (
            [
                ['__compiler_attr_class_count', $sizeT, [$i8p]],
                ['__compiler_attr_class_name_at', $i8p, [$i8p, $sizeT]],
                ['__compiler_attr_method_count', $sizeT, [$i8p, $i8p]],
                ['__compiler_attr_method_name_at', $i8p, [$i8p, $i8p, $sizeT]],
                ['__compiler_attr_class_args_hashtable', $context->getTypeFromString('__hashtable__*'), [$i8p, $sizeT]],
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

        if (CompilerVersion::supportsReflectionParameterIsSensitiveParameter()) {
            $i1 = $context->getTypeFromString('int1');
            $i64 = $context->getTypeFromString('int64');
            foreach (
                [
                    ['__compiler_param_func_is_sensitive', $i1, [$i8p, $i64]],
                    ['__compiler_param_method_is_sensitive', $i1, [$i8p, $i8p, $i64]],
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

        if (CompilerVersion::supportsReflectionFunctionGetNamedArguments()) {
            foreach (
                [
                    ['__compiler_refl_func_named_count', $sizeT, [$i8p]],
                    ['__compiler_refl_func_named_at', $i8p, [$i8p, $sizeT]],
                    ['__compiler_refl_method_named_count', $sizeT, [$i8p, $i8p]],
                    ['__compiler_refl_method_named_at', $i8p, [$i8p, $i8p, $sizeT]],
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

        $i1 = $context->getTypeFromString('int1');
        $variadicAbi = '__compiler_refl_func_is_variadic';
        $existingVariadic = $context->module->getNamedFunction($variadicAbi);
        if (null !== $existingVariadic) {
            $context->registerFunction($variadicAbi, $existingVariadic);
        } else {
            $ft = $context->context->functionType($i1, false, $i8p);
            $fn = $context->module->addFunction($variadicAbi, $ft);
            $context->registerFunction($variadicAbi, $fn);
        }
    }
}
