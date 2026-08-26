<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbInternalEncodingRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_internal_encoding() (#13100, #20014, #35221 runtime encoding).
 *
 * Compile-time fold updates {@see MbstringAotFoldState} for other mb_* folds; runtime
 * canonicalize via NestedJIT {@see MbInternalEncodingJitHelper}; mutable code in module
 * global (peer libxml AOT ring — NestedJIT statics are not reliable).
 */
final class JitMbInternalEncoding
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_internal_encoding() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (0 === $argc
            || (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant)
        ) {
            return self::lowerGet($context);
        }

        $encodingLit = JitStringArg::compileTimeLiteral($args[0]);
        $canonicalLit = null;
        if (null !== $encodingLit) {
            $canonicalLit = MbstringEncodingRegistry::assertValid(
                $encodingLit,
                'mb_internal_encoding',
                0
            );
            MbstringAotFoldState::setInternalEncoding($context, $canonicalLit);
        }

        return self::lowerSet($context, $args[0], $canonicalLit);
    }

    private static function lowerGet(Context $context): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbInternalEncodingRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_internal_encoding_get');

        $g = MbInternalEncodingRuntime::encodingCodeGlobal($context);
        $code = $context->builder->load($g);
        $i64 = $context->getTypeFromString('int64');

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $doneBb = BasicBlockHelper::append($context, 'mb_internal_encoding_get_done');

        $names = [
            MbInternalEncodingJitHelper::CODE_UTF8 => 'UTF-8',
            MbInternalEncodingJitHelper::CODE_ASCII => 'ASCII',
            MbInternalEncodingJitHelper::CODE_ISO88591 => 'ISO-8859-1',
            MbInternalEncodingJitHelper::CODE_SJIS => 'SJIS',
            MbInternalEncodingJitHelper::CODE_EUCJP => 'EUC-JP',
            MbInternalEncodingJitHelper::CODE_8BIT => '8BIT',
        ];
        $next = null;
        foreach ($names as $codeVal => $name) {
            $matchBb = BasicBlockHelper::append($context, 'mb_internal_encoding_get_'.$codeVal);
            $elseBb = BasicBlockHelper::append($context, 'mb_internal_encoding_get_not_'.$codeVal);
            if (null === $next) {
                // First comparison uses current insert block.
            } else {
                $context->builder->positionAtEnd($next);
            }
            $isMatch = $context->builder->icmp(
                Builder::INT_EQ,
                $code,
                $i64->constInt($codeVal, false)
            );
            $context->builder->branchIf($isMatch, $matchBb, $elseBb);

            $context->builder->positionAtEnd($matchBb);
            self::writeStringConstant($context, $ptr, $name);
            $context->builder->branch($doneBb);

            $next = $elseBb;
        }

        // 0 / unknown → UTF-8 default.
        $context->builder->positionAtEnd($next);
        self::writeStringConstant($context, $ptr, 'UTF-8');
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
    }

    private static function lowerSet(Context $context, JITVariable $arg, ?string $canonicalLit): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbInternalEncodingRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_internal_encoding_set');

        $i64 = $context->getTypeFromString('int64');
        if (null !== $canonicalLit) {
            $code = $i64->constInt(self::codeForCanonical($canonicalLit), false);
        } else {
            $enc = JitStringBuiltinArg::lower(
                $context,
                $arg,
                'mb_internal_encoding',
                0,
                'encoding'
            );
            $raw = JitNestedHelperCoerce::callHelper(
                $context,
                MbInternalEncodingRuntime::canonicalizeHelper($context),
                [$enc]
            );
            $code = JitNestedHelperCoerce::extractLongFromHelperResult($context, $raw, $i64);
        }

        $g = MbInternalEncodingRuntime::encodingCodeGlobal($context);
        $context->builder->store($code, $g);

        return $context->constantFromBool(true);
    }

    private static function writeStringConstant(Context $context, Value $valuePtr, string $name): void
    {
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($name))
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $valuePtr,
            $owned
        );
    }

    private static function codeForCanonical(string $canonical): int
    {
        return match ($canonical) {
            'UTF-8' => MbInternalEncodingJitHelper::CODE_UTF8,
            'ASCII' => MbInternalEncodingJitHelper::CODE_ASCII,
            'ISO-8859-1' => MbInternalEncodingJitHelper::CODE_ISO88591,
            'SJIS' => MbInternalEncodingJitHelper::CODE_SJIS,
            'EUC-JP' => MbInternalEncodingJitHelper::CODE_EUCJP,
            '8BIT' => MbInternalEncodingJitHelper::CODE_8BIT,
            default => MbInternalEncodingJitHelper::CODE_UTF8,
        };
    }
}
