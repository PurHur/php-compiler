<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbSubstrCountRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for mb_substr_count() — MbSubstrCountJitHelper in-module (#4637 AOT leftover).
 */
final class JitMbSubstrCount
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('mb_substr_count() requires two or three arguments');
        }

        if (
            2 === $argc
            && JITVariable::TYPE_STRING === $args[0]->type
            && JITVariable::TYPE_STRING === $args[1]->type
            && null !== ($args[0]->compileTimeString ?? null)
            && null !== ($args[1]->compileTimeString ?? null)
            && '' !== $args[1]->compileTimeString
        ) {
            return $context->constantFromInteger(
                VmString::substr_count($args[0]->compileTimeString, $args[1]->compileTimeString),
                'int64'
            );
        }

        $haystack = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'mb_substr_count',
            0,
            'haystack'
        );
        $needle = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[1],
            'mb_substr_count',
            1,
            'needle'
        );
        $encoding = self::runtimeEncodingLiteral($args, $argc, $context);
        self::assertSupportedEncoding($encoding);

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbSubstrCountRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $encPtr = $context->builder->load($context->constantStringFromString($encoding));

        return $context->builder->call(
            MbSubstrCountRuntime::substrCountHelper($context),
            $haystack,
            $needle,
            $encPtr
        );
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function runtimeEncodingLiteral(array $args, int $argc, Context $context): string
    {
        if ($argc < 3) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false)) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_STRING !== $args[2]->type) {
            throw new \LogicException(
                'mb_substr_count() encoding must be a string literal in this compiler build'
            );
        }
        $encoding = $args[2]->compileTimeString ?? null;
        if (null === $encoding) {
            throw new \LogicException(
                'mb_substr_count() encoding must be a string literal in this compiler build'
            );
        }

        return $encoding;
    }

    private static function assertSupportedEncoding(string $encoding): void
    {
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_substr_count() requires UTF-8, ASCII, or 8BIT encoding in this compiler build'
            );
        }
    }
}
