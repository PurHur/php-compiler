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
 * ReflectionClassConstant::__construct($class, $name) — thin AOT (#33990).
 *
 * Seeds Zend public `$class` / `$name` (#22503). Distinct from ReflectionConstant's
 * `$name`+`$constant` layout (#25963).
 */
final class ReflectionClassConstantConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        if (\count($args) < 3) {
            \PHPCompiler\VM\ReflectionSupport::throwConstructArgumentCountError(
                'ReflectionClassConstant',
                2,
                max(0, \max(0, count($args) - 1))
            );
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
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
