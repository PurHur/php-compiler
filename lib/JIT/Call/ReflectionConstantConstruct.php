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

/** ReflectionConstant::__construct($name) or ($class, $name) — JIT (#4136, #17341). */
final class ReflectionConstantConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        if (\count($args) < 2) {
            throw new \ArgumentCountError(
                'ReflectionConstant::__construct() expects at least 1 argument, 0 given'
            );
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        if (\count($args) < 3) {
            $i8p = $context->getTypeFromString('int8*');
            ReflectionSetup::emitSetStringPropertyFromCstr(
                $context,
                $obj,
                'ReflectionConstant',
                'name',
                $context->builder->pointerCast($context->constantFromString(''), $i8p),
                $context->getTypeFromString('size_t')->constInt(0, false)
            );
            ReflectionSetup::emitSetStringPropertyFromVar($context, $obj, 'ReflectionConstant', 'constant', $args[1]);
            ReflectionSetup::markConstructed($context, $obj);
        } else {
            ReflectionSetup::emitSetStringPropertyFromVar($context, $obj, 'ReflectionConstant', 'name', $args[1]);
            ReflectionSetup::emitSetStringPropertyFromVar($context, $obj, 'ReflectionConstant', 'constant', $args[2]);
            ReflectionSetup::markConstructed($context, $obj);
        }

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
