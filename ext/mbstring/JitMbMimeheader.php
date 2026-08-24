<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbMimeheaderRuntime;
use PHPCompiler\JIT\Builtin\StringBase64Decode;
use PHPCompiler\JIT\Builtin\StringBase64Encode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_encode_mimeheader()/mb_decode_mimeheader() (#6038, #34299, #34310).
 *
 * Compile-time fold for string literals; runtime via NestedJIT {@see MbMimeheaderJitHelper}
 * through {@see JitNestedHelperCoerce} (raw call / VmMbstring TU SIGSEGVs — leftover #34307).
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

        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c; #21430).
        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_encode_mimeheader', 0, 'str');
        $charset = self::runtimeCharsetLiteral($args, $argc);
        self::assertSupportedCharset($charset);
        $transfer = self::runtimeTransferLiteral($args, $argc);

        // NestedJIT helper compile can clear insert; restore before coerce/call (#34270).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringBase64Encode::ensureLinked($context);
        StringBase64Decode::ensureLinked($context);
        MbMimeheaderRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        $charsetPtr = $context->builder->load($context->constantStringFromString($charset));
        $transferPtr = $context->builder->load($context->constantStringFromString($transfer));
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

        // Soft-null DEP+coerce (non-strict) / TypeError path handled by caller (#30311).
        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_decode_mimeheader', 0, 'string');

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringBase64Encode::ensureLinked($context);
        StringBase64Decode::ensureLinked($context);
        MbMimeheaderRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
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
     * Match {@see mb_encode_mimeheader::execute}: omitted charset is UTF-8.
     *
     * @param list<JITVariable> $args
     */
    private static function runtimeCharsetLiteral(array $args, int $argc): string
    {
        if ($argc < 2 || !isset($args[1]) || JITVariable::TYPE_NULL === $args[1]->type
            || ($args[1]->isNullConstant ?? false)) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException(
                'mb_encode_mimeheader() charset must be a string literal in this compiler build'
            );
        }
        $charset = $args[1]->compileTimeString ?? null;
        if (null === $charset) {
            throw new \LogicException(
                'mb_encode_mimeheader() charset must be a string literal in this compiler build'
            );
        }

        return $charset;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function runtimeTransferLiteral(array $args, int $argc): string
    {
        if ($argc < 3 || !isset($args[2]) || JITVariable::TYPE_NULL === $args[2]->type
            || ($args[2]->isNullConstant ?? false)) {
            return 'B';
        }
        if (JITVariable::TYPE_STRING !== $args[2]->type) {
            throw new \LogicException(
                'mb_encode_mimeheader() transfer_encoding must be a string literal in this compiler build'
            );
        }
        $transfer = $args[2]->compileTimeString ?? null;
        if (null === $transfer) {
            throw new \LogicException(
                'mb_encode_mimeheader() transfer_encoding must be a string literal in this compiler build'
            );
        }

        return $transfer;
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
