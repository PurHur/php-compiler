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
use PHPLLVM\Value;

/** ReflectionProperty::__construct($class, $name) — JIT (#4136). */
final class ReflectionPropertyConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        if (\count($args) < 3) {
            throw new \LogicException('ReflectionProperty::__construct() expects class and property name');
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        // Zend ReflectionProperty::$name = property name; $class = class arg (declaring when same) (#22504).
        ReflectionSetup::emitSetStringPropertyFromVar($context, $obj, 'ReflectionProperty', 'name', $args[2]);
        ReflectionSetup::emitSetStringPropertyFromVar($context, $obj, 'ReflectionProperty', 'class', $args[1]);
        ReflectionSetup::markConstructed($context, $obj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
