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
 * ReflectionExtension::__construct(string $name) — JIT/AOT thin path (#34003).
 *
 * Mirrors VM {@see \PHPCompiler\VM\Builtin\ReflectionExtensionConstruct} for the
 * common string-name shape. php-src: zim_ReflectionExtension___construct
 * (ext/reflection/php_reflection.c).
 *
 * Must be listed in JIT::isVoidJitConstructCall so markObjectConstructed runs.
 */
final class ReflectionExtensionConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            ReflectionSupport::throwConstructArgumentCountError(
                'ReflectionExtension',
                1,
                max(0, \max(0, count($args) - 1))
            );
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        ReflectionSetup::emitSetStringPropertyFromVar(
            $context,
            $obj,
            'ReflectionExtension',
            ReflectionSupport::PROP_EXTENSION_NAME,
            $args[1]
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
