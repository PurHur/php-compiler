<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Iconv as IconvRuntimeLink;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for iconv() (issue #6009, #6251; php-src ext/iconv/iconv.c). */
final class JitIconv
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('iconv() requires exactly three arguments');
        }

        $fromLit = JitStringBuiltinArg::compileTimeLiteral($args[0]);
        $toLit = JitStringBuiltinArg::compileTimeLiteral($args[1]);
        $inputLit = JitStringBuiltinArg::compileTimeLiteral($args[2]);
        if (
            null !== $fromLit
            && null !== $toLit
            && null !== $inputLit
            && null !== CharsetEngine::parseEncodingSpec($fromLit)
            && null !== CharsetEngine::parseEncodingSpec($toLit)
        ) {
            return self::foldCompileTime($context, $fromLit, $toLit, $inputLit);
        }

        IconvRuntimeLink::ensureLinked($context);

        $from = JitStringBuiltinArg::lowerRequiredString($context, $args[0], 'iconv', 0, 'from_encoding');
        $to = JitStringBuiltinArg::lowerRequiredString($context, $args[1], 'iconv', 1, 'to_encoding');
        $input = JitStringBuiltinArg::lower($context, $args[2], 'iconv', 2, 'string');

        $result = $context->builder->call(
            $context->lookupFunction('__compiler_iconv'),
            $from,
            $to,
            $input
        );

        return self::materializeStringOrFalse($context, $result);
    }

    private static function foldCompileTime(Context $context, string $from, string $to, string $input): Value
    {
        $converted = VmIconv::iconv($from, $to, $input);
        if (false === $converted) {
            return $context->getTypeFromString('bool')->constInt(0, false);
        }

        return $context->constantFromString($converted);
    }

    private static function materializeStringOrFalse(Context $context, Value $contents): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'iconv_fail');
        $okBlock = BasicBlockHelper::append($context, 'iconv_ok');
        $doneBlock = BasicBlockHelper::append($context, 'iconv_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $contents
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
