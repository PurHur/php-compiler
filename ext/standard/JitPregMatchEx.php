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

/** LLVM JIT/AOT for preg_match() with $matches, flags, and offset (issue #4417). */
final class JitPregMatchEx
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 5) {
            throw new \LogicException('preg_match() requires 2 to 5 arguments in this compiler build');
        }

        StringPregMatch::ensureLinked($context);

        // Z_PARAM_STR $pattern — null TypeError on 8.4 (#20226).
        // $subject soft-null DEP+coerce on 8.4 (#21198; php-src php_pcre.c).
        if ($context->callerStrictTypes) {
            $pattern = JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'preg_match', 0, 'pattern');
            $subject = JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'preg_match', 1, 'subject');
        } else {
            $pattern = JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'preg_match', 0, 'pattern');
            $subject = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'preg_match', 1, 'subject');
        }

        if (2 === $argc) {
            return JitPregMatch::invoke($context, $pattern, $subject);
        }

        $i64 = $context->getTypeFromString('int64');
        $flags = $i64->constInt(0, false);
        $offset = $i64->constInt(0, false);
        if (isset($args[3]) && !NamedOptionalCallArgs::isOmittedOptional($args[3])) {
            $flags = JitLongArg::lower($context, $args[3], 'preg_match() flags');
        }
        if (isset($args[4]) && !NamedOptionalCallArgs::isOmittedOptional($args[4])) {
            $offset = JitLongArg::lower($context, $args[4], 'preg_match() offset');
        }

        $matchesPtr = JitValueBox::valuePtrFromVariable($context, $args[2]);
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_preg_match_ex'),
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
        $failBlock = BasicBlockHelper::append($context, 'preg_match_ex_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'preg_match_ex_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'preg_match_ex_done_'.$id);

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
