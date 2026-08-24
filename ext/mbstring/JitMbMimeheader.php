<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbMimeheaderRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_encode_mimeheader()/mb_decode_mimeheader() (#6038, #34299).
 *
 * Compile-time fold for string literals; runtime via NestedJIT {@see MbMimeheaderJitHelper}.
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
        $charset = self::lowerOptionalString(
            $context,
            $args,
            $argc,
            1,
            'UTF-8',
            'mb_encode_mimeheader',
            'charset'
        );
        $transfer = self::lowerOptionalString(
            $context,
            $args,
            $argc,
            2,
            'B',
            'mb_encode_mimeheader',
            'transfer_encoding'
        );
        $linefeed = self::lowerOptionalString(
            $context,
            $args,
            $argc,
            3,
            "\r\n",
            'mb_encode_mimeheader',
            'linefeed'
        );
        if ($argc >= 5) {
            $indent = JitStrictIntArg::lower($context, $args[4], 'mb_encode_mimeheader', 5, 'indent');
        } else {
            $indent = $context->getTypeFromString('int64')->constInt(0, false);
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbMimeheaderRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $resultStr = $context->builder->call(
            MbMimeheaderRuntime::encodeHelper($context),
            $str,
            $charset,
            $transfer,
            $linefeed,
            $indent
        );

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

        // Soft-null DEP+coerce when non-strict (php-src Z_PARAM_STR; #30311).
        $str = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'mb_decode_mimeheader',
            0,
            'string'
        );

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbMimeheaderRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $resultStr = $context->builder->call(
            MbMimeheaderRuntime::decodeHelper($context),
            $str
        );

        return self::materializeOwnedString($context, $resultStr);
    }

    /**
     * @param JITVariable[] $args
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
        $charset = self::compileTimeEncoding($args, 1);
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
        // linefeed / indent: NestedJIT when present (fold covers str+charset+transfer).
        if (isset($args[3]) || isset($args[4])) {
            return null;
        }

        return self::materializeOwnedString(
            $context,
            $context->builder->load(
                $context->constantStringFromString(
                    MimeHeaderConvert::encode($string, $charset, $base64)
                )
            )
        );
    }

    /**
     * @param JITVariable[] $args
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

        return self::materializeOwnedString(
            $context,
            $context->builder->load(
                $context->constantStringFromString(
                    MimeHeaderConvert::decode($string)
                )
            )
        );
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function lowerOptionalString(
        Context $context,
        array $args,
        int $argc,
        int $index,
        string $default,
        string $function,
        string $paramName
    ): Value {
        if ($argc <= $index) {
            return $context->builder->load($context->constantStringFromString($default));
        }
        if (JITVariable::TYPE_NULL === $args[$index]->type || ($args[$index]->isNullConstant ?? false)) {
            return $context->builder->load($context->constantStringFromString($default));
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[$index],
            $function,
            $index,
            $paramName
        );
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeEncoding(array $args, int $index): ?string
    {
        if (!isset($args[$index]) || JITVariable::TYPE_NULL === $args[$index]->type) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }

        return $args[$index]->compileTimeString ?? null;
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
