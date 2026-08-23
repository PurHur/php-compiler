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
 * ReflectionMethod::__construct($objectOrMethod, $method) — thin AOT (#33990).
 *
 * Seeds Zend public `$class` / `$name` so property reads and getAttributes() do not SIGSEGV.
 * Two-arg form only (class name + method); mirrors ReflectionPropertyConstruct (#4136).
 */
final class ReflectionMethodConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        if (\count($args) < 3) {
            \PHPCompiler\VM\ReflectionSupport::throwConstructArgumentCountError(
                'ReflectionMethod',
                2,
                max(0, \max(0, count($args) - 1))
            );
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        // php-src zim_ReflectionMethod___construct — $class = declaring class, $name = method.
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
