<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbHttpOutputRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_http_output() (#13100, #20014, #35231 runtime encoding).
 *
 * Compile-time fold updates {@see MbstringAotFoldState}; runtime canonicalize via NestedJIT
 * {@see MbHttpOutputJitHelper}; mutable code in module global (peer {@see JitMbInternalEncoding}).
 */
final class JitMbHttpOutput
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_http_output() expects at most 1 argument, %d given',
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
            if (0 === \strcasecmp($encodingLit, 'pass')) {
                $canonicalLit = 'pass';
            } else {
                $canonicalLit = MbstringEncodingRegistry::assertValid(
                    $encodingLit,
                    'mb_http_output',
                    0
                );
            }
            MbstringAotFoldState::setHttpOutput($context, $canonicalLit);
        }

        return self::lowerSet($context, $args[0], $canonicalLit);
    }

    private static function lowerGet(Context $context): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbHttpOutputRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_http_output_get');

        $g = MbHttpOutputRuntime::encodingCodeGlobal($context);
        $code = $context->builder->load($g);
        $i64 = $context->getTypeFromString('int64');

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $doneBb = BasicBlockHelper::append($context, 'mb_http_output_get_done');

        $names = [
            MbHttpOutputJitHelper::CODE_UTF8 => 'UTF-8',
            MbHttpOutputJitHelper::CODE_ASCII => 'ASCII',
            MbHttpOutputJitHelper::CODE_ISO88591 => 'ISO-8859-1',
            MbHttpOutputJitHelper::CODE_SJIS => 'SJIS',
            MbHttpOutputJitHelper::CODE_EUCJP => 'EUC-JP',
            MbHttpOutputJitHelper::CODE_8BIT => '8BIT',
            MbHttpOutputJitHelper::CODE_PASS => 'pass',
        ];
        $next = null;
        foreach ($names as $codeVal => $name) {
            $matchBb = BasicBlockHelper::append($context, 'mb_http_output_get_'.$codeVal);
            $elseBb = BasicBlockHelper::append($context, 'mb_http_output_get_not_'.$codeVal);
            if (null !== $next) {
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

        // 0 / unknown → UTF-8 default (php-src mbstring.http_output default).
        $context->builder->positionAtEnd($next);
        self::writeStringConstant($context, $ptr, 'UTF-8');
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
    }

    private static function lowerSet(Context $context, JITVariable $arg, ?string $canonicalLit): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbHttpOutputRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_http_output_set');

        $i64 = $context->getTypeFromString('int64');
        if (null !== $canonicalLit) {
            $code = $i64->constInt(self::codeForCanonical($canonicalLit), false);
        } else {
            $enc = JitStringBuiltinArg::lower(
                $context,
                $arg,
                'mb_http_output',
                0,
                'encoding'
            );
            $raw = JitNestedHelperCoerce::callHelper(
                $context,
                MbHttpOutputRuntime::canonicalizeHelper($context),
                [$enc]
            );
            $code = JitNestedHelperCoerce::extractLongFromHelperResult($context, $raw, $i64);
        }

        $g = MbHttpOutputRuntime::encodingCodeGlobal($context);
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
            'UTF-8' => MbHttpOutputJitHelper::CODE_UTF8,
            'ASCII' => MbHttpOutputJitHelper::CODE_ASCII,
            'ISO-8859-1' => MbHttpOutputJitHelper::CODE_ISO88591,
            'SJIS' => MbHttpOutputJitHelper::CODE_SJIS,
            'EUC-JP' => MbHttpOutputJitHelper::CODE_EUCJP,
            '8BIT' => MbHttpOutputJitHelper::CODE_8BIT,
            'pass' => MbHttpOutputJitHelper::CODE_PASS,
            default => MbHttpOutputJitHelper::CODE_UTF8,
        };
    }
}
