<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/**
 * ReflectionParameter::__construct($function, $parameter) — JIT/AOT thin path (#33993).
 *
 * Mirrors VM {@see \PHPCompiler\VM\Builtin\ReflectionParameterConstruct} for the common
 * string function + string|int parameter shape. php-src: zim_ReflectionParameter___construct
 * (ext/reflection/php_reflection.c).
 */
final class ReflectionParameterConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 3) {
            ReflectionSupport::throwConstructArgumentCountError(
                'ReflectionParameter',
                2,
                max(0, \max(0, count($args) - 1))
            );
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        // Public Zend surface is `$name`; also stash declaring function for getName/is* (#22528).
        ReflectionSetup::emitSetStringPropertyFromVar(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_FUNC_NAME,
            $args[1]
        );
        ReflectionSetup::emitSetStringPropertyFromVar(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_PARAM_NAME,
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
