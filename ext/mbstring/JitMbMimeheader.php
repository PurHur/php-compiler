<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbMimeheaderRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_encode_mimeheader()/mb_decode_mimeheader() (#6038, #34299, #35225).
 *
 * Compile-time fold for string literals; runtime via NestedJIT {@see MbMimeheaderJitHelper}.
 * Peer {@see JitMbConvertEncoding} / {@see JitMbScrub} — link NestedJIT before arg lower (#34270).
 * Runtime charset/transfer via {@see JitStringBuiltinArg} (#35225 leftover of #34299).
 */
final class JitMbMimeheader
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invokeEncode(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 5) {
            throw new \LogicException('mb_encode_mimeheader() requires one to five arguments');
        }

        $folded = self::tryEncodeCompileTimeFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        // Link NestedJIT helpers before lowering args — NestedJIT can invalidate prior IR (#34270 / #35225).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbMimeheaderRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_encode_mimeheader_runtime');

        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c; #21430).
        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_encode_mimeheader', 0, 'str');
        $charsetPtr = self::charsetPtr($context, $args, $argc);
        $transferPtr = self::transferPtr($context, $args, $argc);

        // Three-string NestedJIT ABI — coerce params/results (Module.php call mismatch / #35225).
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbMimeheaderRuntime::encodeHelper($context),
            [$str, $charsetPtr, $transferPtr]
        );
        $resultStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);

        return self::materializeOwnedString($context, $resultStr);
    }

    /**
     * @param list<JITVariable> $args
     */
    public static function invokeDecode(Context $context, array $args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \LogicException('mb_decode_mimeheader() requires exactly one argument');
        }

        $folded = self::tryDecodeCompileTimeFold($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbMimeheaderRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_decode_mimeheader_runtime');

        // Soft-null DEP+coerce (non-strict) / TypeError path handled by caller (#30311).
        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_decode_mimeheader', 0, 'string');

        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbMimeheaderRuntime::decodeHelper($context),
            [$str]
        );
        $resultStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);

        return self::materializeOwnedString($context, $resultStr);
    }

    /**
     * @param list<JITVariable> $args
     */
    public static function tryEncodeCompileTimeFold(Context $context, array $args): ?Value
    {
        if (!isset($args[0])) {
            return null;
        }
        // Soft-null — do not fold; recover via NestedJIT (#21430).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            return null;
        }
        $string = JitStringArg::compileTimeLiteral($args[0]);
        if (null === $string) {
            return null;
        }
        $charset = self::compileTimeCharset($args, \count($args));
        if (null === $charset) {
            return null;
        }
        $base64 = true;
        if (isset($args[2]) && JITVariable::TYPE_NULL !== $args[2]->type) {
            if (JITVariable::TYPE_STRING !== $args[2]->type) {
                return null;
            }
            $transfer = $args[2]->compileTimeString ?? null;
            if (null === $transfer) {
                return null;
            }
            if ('' !== $transfer) {
                $base64 = 'B' === $transfer[0] || 'b' === $transfer[0];
            }
        }

        // Fold returns a raw __string__* (pre-#34299 shape). Boxing fold results and then
        // feeding them into NestedJIT crashes the same way NestedJIT→NestedJIT does for
        // mb_convert_kana (#34294); keep the literal path as a plain constant string.
        return $context->builder->load(
            $context->constantStringFromString(
                VmMbstring::encodeMimeheader($string, $charset, $base64)
            )
        );
    }

    /**
     * @param list<JITVariable> $args
     */
    public static function tryDecodeCompileTimeFold(Context $context, array $args): ?Value
    {
        if (!isset($args[0])) {
            return null;
        }
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            return null;
        }
        $string = JitStringArg::compileTimeLiteral($args[0]);
        if (null === $string) {
            return null;
        }

        return $context->builder->load(
            $context->constantStringFromString(
                VmMbstring::decodeMimeheader($string)
            )
        );
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function compileTimeCharset(array $args, int $argc): ?string
    {
        if ($argc < 2 || !isset($args[1]) || JITVariable::TYPE_NULL === $args[1]->type) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_STRING !== $args[1]->type) {
            return null;
        }

        return $args[1]->compileTimeString ?? null;
    }

    /**
     * Omitted/null charset → UTF-8; literal → constant; else NestedJIT string (#35225).
     *
     * @param list<JITVariable> $args
     */
    private static function charsetPtr(Context $context, array $args, int $argc): Value
    {
        if ($argc < 2 || !isset($args[1]) || JITVariable::TYPE_NULL === $args[1]->type
            || ($args[1]->isNullConstant ?? false)) {
            return $context->builder->load($context->constantStringFromString('UTF-8'));
        }
        $charsetLit = JitStringArg::compileTimeLiteral($args[1]);
        if (null !== $charsetLit) {
            self::assertSupportedCharset($charsetLit);

            return $context->builder->load($context->constantStringFromString($charsetLit));
        }

        return JitStringBuiltinArg::lower(
            $context,
            $args[1],
            'mb_encode_mimeheader',
            1,
            'charset'
        );
    }

    /**
     * Omitted/null transfer → B; literal → constant; else NestedJIT string (#35225).
     *
     * @param list<JITVariable> $args
     */
    private static function transferPtr(Context $context, array $args, int $argc): Value
    {
        if ($argc < 3 || !isset($args[2]) || JITVariable::TYPE_NULL === $args[2]->type
            || ($args[2]->isNullConstant ?? false)) {
            return $context->builder->load($context->constantStringFromString('B'));
        }
        $transferLit = JitStringArg::compileTimeLiteral($args[2]);
        if (null !== $transferLit) {
            return $context->builder->load($context->constantStringFromString($transferLit));
        }

        return JitStringBuiltinArg::lower(
            $context,
            $args[2],
            'mb_encode_mimeheader',
            2,
            'transfer_encoding'
        );
    }

    private static function assertSupportedCharset(string $charset): void
    {
        $canonical = MbstringEncodingRegistry::resolve($charset) ?? $charset;
        if ('UTF-8' !== $canonical && 'ASCII' !== $canonical && '8BIT' !== $canonical) {
            throw new \LogicException(
                'mb_encode_mimeheader() JIT only supports UTF-8, ASCII, or 8BIT charset literals in this compiler build'
            );
        }
    }

    private static function materializeOwnedString(Context $context, Value $resultStr): Value
    {
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }
}
