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
 * ReflectionMethod::__construct($class, $method) — JIT/AOT (#33990).
 *
 * Thin AOT previously had no proxy; construction left $class/$name unset and
 * property reads / getAttributes() SIGSEGV'd (ext/reflection/php_reflection.c).
 * Two-arg form only (Class::method single-arg stays on VM / NestedJIT).
 */
final class ReflectionMethodConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        if (\count($args) < 3) {
            ReflectionSupport::throwConstructArgumentCountError(
                'ReflectionMethod',
                2,
                max(0, \count($args) - 1)
            );
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        // Zend ReflectionMethod::$class = declaring class; $name = method (#18298, #22582).
        ReflectionSetup::emitSetStringPropertyFromVar(
            $context,
            $obj,
            'ReflectionMethod',
            ReflectionSupport::PROP_REFLECTION_METHOD_CLASS,
            $args[1]
        );
        ReflectionSetup::emitSetStringPropertyFromVar(
            $context,
            $obj,
            'ReflectionMethod',
            ReflectionSupport::PROP_REFLECTION_METHOD_FUNC,
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
