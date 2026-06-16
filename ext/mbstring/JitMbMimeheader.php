<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_encode_mimeheader()/mb_decode_mimeheader() (#6038).
 */
final class JitMbMimeheader
{
    /**
     * @param JITVariable[] $args
     */
    public static function tryEncodeCompileTimeFold(Context $context, array $args): ?Value
    {
        if (!isset($args[0])) {
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

        return $context->builder->load(
            $context->constantStringFromString(
                VmMbstring::encodeMimeheader($string, $charset, $base64)
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
}
