<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for assert() via __compiler_assert_fail (issue #3157). */
final class JitAssert
{
    /** @return Value int64 1 on pass, 0 on fail (php-cfg infers assert() as int) */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('assert() requires one or two arguments');
        }

        $boolval = new boolval();
        $truthy = $boolval->call($context, $args[0]);

        $failBlock = BasicBlockHelper::append($context, 'assert_fail');
        $doneBlock = BasicBlockHelper::append($context, 'assert_done');
        $context->builder->branchIf($truthy, $doneBlock, $failBlock);

        $context->builder->positionAtEnd($failBlock);
        self::emitFail($context, $args, $argc);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->zExt($truthy, $i64);
    }

    /** @param list<JITVariable> $args */
    private static function emitFail(Context $context, array $args, int $argc): void
    {
        if (2 === $argc) {
            $literal = JitStringArg::compileTimeLiteral($args[1]);
            if (null !== $literal) {
                self::emitFailCstr($context, $literal);

                return;
            }
            if (\in_array($args[1]->type, [JITVariable::TYPE_STRING, JITVariable::TYPE_VALUE], true)) {
                $strPtr = JitStringArg::lower($context, $args[1], 'assert() description');
                $context->builder->call(
                    $context->lookupFunction('__compiler_assert_fail_string'),
                    $strPtr
                );

                return;
            }
            throw new \LogicException(
                'assert() description must be a string in this compiler build'
            );
        }
        self::emitFailCstr($context, 'assert(): assert(false) failed');
    }

    private static function emitFailCstr(Context $context, string $message): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $context->builder->call(
            $context->lookupFunction('__compiler_assert_fail'),
            $msgPtr,
            $msgLen
        );
    }
}
