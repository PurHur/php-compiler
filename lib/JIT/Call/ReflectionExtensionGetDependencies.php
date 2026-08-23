<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionExtensionGetDependenciesRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/**
 * ReflectionExtension::getDependencies() — JIT/AOT (#34155, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod → NULL.
 * Peer {@see ReflectionExtensionGetClassNames} (#34150); VM #22247.
 */
final class ReflectionExtensionGetDependencies implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'ReflectionExtension::getDependencies',
                    0,
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_ext_getdependencies_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$cstr, $len] = ReflectionSetup::stringPropertyAsCstr(
            $context,
            $obj,
            'ReflectionExtension',
            ReflectionSupport::PROP_EXTENSION_NAME
        );

        return ReflectionExtensionGetDependenciesRuntime::emit($context, $cstr, $len);
    }
}
