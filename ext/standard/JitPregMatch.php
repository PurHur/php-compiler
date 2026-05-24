<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringPregMatch;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for preg_match() decision (no by-ref $matches in JIT v1). */
final class JitPregMatch
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context, Value $pattern, Value $subject): Value
    {
        StringPregMatch::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_preg_match'),
            $pattern,
            $subject
        );
        $errorSentinel = $i64->constInt(-1, true);
        $isError = $context->builder->icmp(Builder::INT_EQ, $raw, $errorSentinel);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'preg_match_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'preg_match_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'preg_match_done_'.$id);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->branchIf($isError, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $raw);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
