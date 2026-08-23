<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionClassGetExtensionRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * ReflectionClass::getExtension() — JIT/AOT (#34145, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod → NULL. User classes must
 * return null (php-src); internals return a constructed ReflectionExtension.
 * Peer {@see ReflectionClassGetExtensionName} (#34139);
 * {@see ReflectionClassGetParentClass} (#34069); VM #11462.
 *
 * php-src: zim_ReflectionClass_getExtension
 */
final class ReflectionClassGetExtension implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: zim_ReflectionClass_getExtension — 0 args; $args[0] is $this (#30888)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'ReflectionClass::getExtension',
                    0,
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_getextension_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$cstr, $len] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);

        return ReflectionClassGetExtensionRuntime::emit($context, $cstr, $len);
    }
}
