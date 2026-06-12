<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_scrub() (issue #6050).
 */
final class JitMbScrub
{
    /**
     * @param JITVariable[] $args
     */
    public static function tryCompileTimeFold(Context $context, array $args): ?Value
    {
        if (!isset($args[0])) {
            return null;
        }
        $string = JitStringArg::compileTimeLiteral($args[0]);
        if (null === $string) {
            return null;
        }
        $encoding = self::compileTimeEncoding($args, 1);
        if (null === $encoding) {
            return null;
        }

        return $context->builder->load(
            $context->constantStringFromString(
                VmMbstring::scrub($string, $encoding)
            )
        );
    }

    /**
     * @param JITVariable[] $args
     */
    private static function compileTimeEncoding(array $args, int $index): ?string
    {
        if (!isset($args[$index])) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_NULL === $args[$index]->type) {
            return 'UTF-8';
        }
        if (JITVariable::TYPE_STRING !== $args[$index]->type) {
            return null;
        }

        return $args[$index]->compileTimeString ?? null;
    }
}
