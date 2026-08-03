<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/** ReflectionEnum::__construct($enum) — JIT/AOT (#9892, #27314). */
final class ReflectionEnumConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            \PHPCompiler\VM\ReflectionSupport::throwConstructArgumentCountError(
                'ReflectionEnum',
                1,
                max(0, \max(0, count($args) - 1))
            );
        }
        $literal = JitStringArg::compileTimeLiteral($args[1]);
        if (null !== $literal && !$context->type->object->isEnumClassLc($literal)) {
            throw new \LogicException('ReflectionEnum expects an enum class');
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        if (null !== $literal) {
            $i8p = $context->getTypeFromString('char*');
            $sizeT = $context->getTypeFromString('size_t');
            ReflectionSetup::emitSetStringPropertyFromCstr(
                $context,
                $obj,
                'ReflectionEnum',
                ReflectionSupport::PROP_CLASS_NAME,
                $context->builder->pointerCast($context->constantFromString($literal), $i8p),
                $sizeT->constInt(\strlen($literal), false)
            );
        } else {
            ReflectionSetup::emitSetStringPropertyFromVar(
                $context,
                $obj,
                'ReflectionEnum',
                ReflectionSupport::PROP_CLASS_NAME,
                $args[1]
            );
        }
        ReflectionSetup::markConstructed($context, $obj);

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
