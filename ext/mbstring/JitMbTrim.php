<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbTrimRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TypeErrorRaise;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_trim() / mb_ltrim() / mb_rtrim()
 * (php-src ext/mbstring/mbstring.c; #5957, #9208, #23883, #34379).
 *
 * Compile-time fold for string literals; runtime haystack via NestedJIT
 * {@see MbTrimJitHelper} (peer {@see JitMbScrub}). $characters + encoding stay
 * compile-time literals.
 */
final class JitMbTrim
{
    /**
     * @param JITVariable[] $args
     */
    public static function invoke(Context $context, int $mode, string $function, array $args): Value
    {
        $folded = self::tryCompileTimeFold($context, $mode, $function, $args);
        if (null !== $folded) {
            return $folded;
        }

        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException(
                $function.'() expects 1 to 3 arguments in this compiler build'
            );
        }

        $what = self::compileTimeWhat($args, 1);
        if (false === $what) {
            throw new \LogicException(
                $function.'() characters must be a compile-time string or null in this compiler build'
            );
        }
        $encoding = self::runtimeEncodingLiteral($args, $argc);
        if (null === $encoding) {
            throw new \LogicException(
                $function.'() encoding must be a string literal in this compiler build'
            );
        }
        if (!MbstringEncodingRegistry::isValid($encoding)) {
            return self::emitEncodingValueError($context, $function, $encoding);
        }
        // Mirror VmMbstring::assertTrimEncoding — only UTF-8 / ASCII / 8BIT in this build.
        $canonical = self::canonicalTrimEncoding($encoding);
        if (null === $canonical) {
            throw new \LogicException(
                $function.'() requires mbstring for encoding '.$encoding.' in this compiler build'
            );
        }

        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c; peer mb_scrub #21516).
        $str = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            $function,
            0,
            'string'
        );

        // Empty $characters → no trim (Zend). Decide at compile time — NestedJIT
        // isset-length on helper string params is unreliable (#34379).
        if (null !== $what && '' === $what) {
            return self::materializeOwnedString($context, $str);
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbTrimRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, $function.'_runtime');

        $whatPtr = $context->builder->load(
            $context->constantStringFromString(null === $what ? '' : $what)
        );
        $encPtr = $context->builder->load($context->constantStringFromString($canonical));
        $i64 = $context->getTypeFromString('int64');
        $whatLen = null === $what ? 0 : \strlen($what);
        $resultStr = $context->builder->call(
            MbTrimRuntime::trimHelper($context),
            $str,
            $whatPtr,
            $encPtr,
            $i64->constInt($mode, false),
            $i64->constInt(null === $what ? 1 : 0, false),
            $i64->constInt($whatLen, false)
        );

        return self::materializeOwnedString($context, $resultStr);
    }

    /**
     * @param JITVariable[] $args
     */
    private static function tryCompileTimeFold(Context $context, int $mode, string $function, array $args): ?Value
    {
        if (!isset($args[0])) {
            return null;
        }
        // Zend 8.4 soft-null + DEP (#24176). Do not fold null — fall through to runtime.
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            return null;
        }
        $string = JitStringArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString ?? null;
        if (null === $string) {
            return null;
        }
        $what = self::compileTimeWhat($args, 1);
        if (false === $what) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 2);
        if (null === $encoding) {
            return null;
        }

        // Unknown encoding → do not throw ValueError during IR fold (breaks try/catch).
        // Fall through to runtime invoke which emits catchable ValueError (#23883 / #34379).
        if (!MbstringEncodingRegistry::isValid($encoding)) {
            return null;
        }
        if (null === self::canonicalTrimEncoding($encoding)) {
            return null;
        }

        return self::materializeString(
            $context,
            VmMbstring::trimString($string, $what, $encoding, $mode, $function)
        );
    }

    /**
     * @param JITVariable[] $args
     *
     * @return null|string|false null = use default trim set; false = not foldable
     */
    private static function compileTimeWhat(array $args, int $index): null|string|false
    {
        if (!isset($args[$index])) {
            return null;
        }
        if (JITVariable::TYPE_NULL === $args[$index]->type || ($args[$index]->isNullConstant ?? false)) {
            return null;
        }
        $lit = JitStringArg::compileTimeLiteral($args[$index])
            ?? $args[$index]->compileTimeString
            ?? null;
        if (null === $lit) {
            return false;
        }

        return $lit;
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeEncoding(array $args, int $index): ?string
    {
        if (!isset($args[$index])) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_NULL === $args[$index]->type || ($args[$index]->isNullConstant ?? false)) {
            return 'UTF-8';
        }

        return JitStringArg::compileTimeLiteral($args[$index])
            ?? $args[$index]->compileTimeString
            ?? null;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function runtimeEncodingLiteral(array $args, int $argc): ?string
    {
        if ($argc < 3) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false)) {
            return 'UTF-8';
        }

        return JitStringArg::compileTimeLiteral($args[2]);
    }

    private static function canonicalTrimEncoding(string $encoding): ?string
    {
        try {
            $valid = MbstringEncodingRegistry::assertValid($encoding, 'mb_trim', 2);
        } catch (\ValueError) {
            return null;
        }
        if ('UTF-8' !== $valid && 'ASCII' !== $valid && '8BIT' !== $valid) {
            return null;
        }

        return $valid;
    }

    private static function emitEncodingValueError(Context $context, string $function, string $encoding): Value
    {
        TypeErrorRaise::emitValueError(
            $context,
            sprintf(
                '%s(): Argument #3 ($encoding) must be a valid encoding, "%s" given',
                $function,
                $encoding
            )
        );
        BasicBlockHelper::ensureOpenInsertBlock($context, $function.'_bad_enc_dead');

        return JitValueBox::pointer($context, JitValueBox::alloc($context));
    }

    private static function materializeOwnedString(Context $context, Value $resultStr): Value
    {
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    private static function materializeString(Context $context, string $str): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($str))
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }
}
