<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\CloneWithReinitRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for phpc_clone_with_begin/end (#7250). */
final class JitCloneWithReinit
{
    public static function begin(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('phpc_clone_with_begin() requires at least one argument');
        }
        CloneWithReinitRuntime::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        $names = [];
        for ($i = 1, $c = \count($args); $i < $c; ++$i) {
            $literal = JitStringArg::compileTimeLiteral($args[$i]);
            if (null === $literal) {
                throw new \LogicException(
                    'phpc_clone_with_begin() property names must be compile-time strings in this compiler build'
                );
            }
            $names[] = $literal;
        }
        CloneWithReinitRuntime::emitBegin($context, $obj, $names);

        return JitValueBox::pointer($context, JitValueBox::alloc($context));
    }

    public static function end(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_clone_with_end() requires exactly one argument');
        }
        CloneWithReinitRuntime::ensureLinked($context);
        $obj = self::readObject($context, $args[0]);
        CloneWithReinitRuntime::emitEnd($context, $obj);

        return JitValueBox::pointer($context, JitValueBox::alloc($context));
    }

    public static function reinit(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('phpc_clone_with_reinit() requires exactly two arguments');
        }
        $literal = JitStringArg::compileTimeLiteral($args[1]);
        if (null === $literal) {
            throw new \LogicException(
                'phpc_clone_with_reinit() property name must be a compile-time string in this compiler build'
            );
        }
        $obj = self::readObject($context, $args[0]);
        $classId = $context->type->object->readRuntimeClassId($obj);
        $context->type->object->reinitCloneWithPropertyDefault($obj, $classId, $literal);

        return JitValueBox::pointer($context, JitValueBox::alloc($context));
    }

    private static function readObject(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_VALUE !== $arg->type) {
            throw new \LogicException('phpc_clone_with_*() requires an object operand in this compiler build');
        }

        $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $loaded
        );
        if (null === $obj) {
            throw new \LogicException('phpc_clone_with_*() object read failed in this compiler build');
        }

        return $obj;
    }
}
