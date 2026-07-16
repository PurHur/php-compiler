<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringPregMatch;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT/AOT for preg_match_all() with $matches, flags, and offset (issue #4417). */
final class JitPregMatchAllEx
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 5) {
            throw new \LogicException('preg_match_all() requires 2 to 5 arguments in this compiler build');
        }

        StringPregMatch::ensureLinked($context);

        $pattern = JitStringBuiltinArg::lower($context, $args[0], 'preg_match_all', 0, 'pattern');
        // Z_PARAM_STR $subject — null TypeError on 8.4 forward profile (#19320).
        $subject = JitStringBuiltinArg::lowerZparamStr($context, $args[1], 'preg_match_all', 1, 'subject');

        if (2 === $argc) {
            return JitPregMatchAll::invoke($context, $pattern, $subject);
        }

        $i64 = $context->getTypeFromString('int64');
        $flags = $i64->constInt(0, false);
        $offset = $i64->constInt(0, false);
        if (isset($args[3]) && !NamedOptionalCallArgs::isOmittedOptional($args[3])) {
            $flags = JitLongArg::lower($context, $args[3], 'preg_match_all() flags');
        }
        if (isset($args[4]) && !NamedOptionalCallArgs::isOmittedOptional($args[4])) {
            $offset = JitLongArg::lower($context, $args[4], 'preg_match_all() offset');
        }

        $matchesPtr = JitValueBox::valuePtrFromVariable($context, $args[2]);
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_preg_match_all_ex'),
            $pattern,
            $subject,
            $matchesPtr,
            $flags,
            $offset
        );

        return self::boxMatchCount($context, $raw);
    }

    public static function boxMatchCount(Context $context, Value $raw): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $errorSentinel = $i64->constInt(-1, true);
        $isError = $context->builder->icmp(Builder::INT_EQ, $raw, $errorSentinel);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'preg_match_all_ex_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'preg_match_all_ex_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'preg_match_all_done_'.$id);

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
