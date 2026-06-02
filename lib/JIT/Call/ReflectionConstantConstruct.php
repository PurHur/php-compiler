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

/** ReflectionConstant::__construct($class, $name) — JIT (#4136). */
final class ReflectionConstantConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        if (\count($args) < 3) {
            throw new \LogicException('ReflectionConstant::__construct() expects class and constant name');
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        ReflectionSetup::emitSetStringPropertyFromVar($context, $obj, 'ReflectionConstant', 'name', $args[1]);
        ReflectionSetup::emitSetStringPropertyFromVar($context, $obj, 'ReflectionConstant', 'constant', $args[2]);
        ReflectionSetup::markConstructed($context, $obj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
