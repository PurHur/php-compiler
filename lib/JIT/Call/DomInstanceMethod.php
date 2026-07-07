<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\DomInstanceMethodRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** ext/dom instance methods — JIT/AOT via DomInstanceMethodJitHelper (#17130). */
final class DomInstanceMethod implements Call
{
    public function __construct(
        private readonly string $classLc,
        private readonly string $methodLc,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException($this->classLc.'::'.$this->methodLc.'() called without $this');
        }

        $receiverBox = self::operandToValueBox($context, $args[0]);
        $argsHt = HashTableHelper::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        foreach (\array_slice($args, 1) as $i => $arg) {
            HashTableHelper::setAtIndex(
                $context,
                $argsHt,
                $i64->constInt($i, false),
                $arg
            );
        }
        $argsBox = self::boxedHashtable($context, $argsHt);

        if (!NestedJitCompileScope::isActive()) {
            DomInstanceMethodRuntime::ensureLinked($context);
        }

        return DomInstanceMethodRuntime::invoke($context, $receiverBox, $this->methodLc, $argsBox);
    }

    private static function operandToValueBox(Context $context, Variable $arg): Value
    {
        if (Variable::TYPE_VALUE === $arg->type) {
            return JitValueBox::valuePtrFromVariable($context, $arg);
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (Variable::TYPE_OBJECT === $arg->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $ptr,
                $context->helper->loadValue($arg)
            );

            return $ptr;
        }

        throw new \LogicException('DOM instance method receiver must be object or value box');
    }

    private static function boxedHashtable(Context $context, Value $ht): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }
}
