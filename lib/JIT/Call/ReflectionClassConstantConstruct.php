<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/**
 * ReflectionClassConstant::__construct($class, $name) — JIT/AOT (#33990).
 *
 * Sibling of ReflectionMethodConstruct: without a thin-AOT proxy, $class/$name
 * stayed unset and property reads SIGSEGV'd (ext/reflection/php_reflection.c).
 */
final class ReflectionClassConstantConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        if (\count($args) < 3) {
            $given = max(0, \count($args) - 1);
            throw new \ArgumentCountError(
                'ReflectionClassConstant::__construct() expects exactly 2 arguments, '.$given.' given'
            );
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        // Zend ReflectionClassConstant::$class / $name (#22503, #22581).
        ReflectionSetup::emitSetStringPropertyFromVar(
            $context,
            $obj,
            'ReflectionClassConstant',
            ReflectionSupport::PROP_REFLECTION_CLASS_CONSTANT_CLASS,
            $args[1]
        );
        ReflectionSetup::emitSetStringPropertyFromVar(
            $context,
            $obj,
            'ReflectionClassConstant',
            ReflectionSupport::PROP_REFLECTION_CLASS_CONSTANT_NAME,
            $args[2]
        );
        ReflectionSetup::markConstructed($context, $obj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
