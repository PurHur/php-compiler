<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for mb_detect_encoding() (#3075, soft-null #21516).
 */
final class JitMbDetectEncoding
{
    /**
     * @param JITVariable[] $args
     */
    public static function tryCompileTimeFold(Context $context, array $args): ?Value
    {
        if (!isset($args[0])) {
            return null;
        }
        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c; #21516, reverts #20225 TypeError).
        if (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant) {
            if ($context->callerStrictTypes) {
                return JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'mb_detect_encoding', 0, 'string');
            }
            $string = '';
        } else {
            $string = JitStringArg::compileTimeLiteral($args[0]);
            if (null === $string) {
                return null;
            }
        }
        // Only fold the one-arg form (default detect order); list/strict need runtime.
        if (\count($args) > 1) {
            return null;
        }
        $result = VmMbstring::detectEncoding($string, null, false);
        if (false === $result) {
            return $context->constantFromBool(false);
        }

        return $context->builder->load($context->constantStringFromString($result));
    }
}
