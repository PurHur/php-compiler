<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringNl2br;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * nl2br() for strings (subset of PHP; JIT/AOT via StringNl2br + Nl2brJitHelper).
 */
final class nl2br extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('nl2br() requires one or two arguments');
        }
        $subject = self::vmStringArg($frame, 0, 'string');
        $useXhtml = true;
        if (2 === $argc) {
            // Z_PARAM_BOOL — convert_to_boolean incl. null→false (#4293, php-src string.c).
            $useXhtml = VmMath::parseBoolBuiltinArgForFrame($frame, 1, 'nl2br', 2, 'use_xhtml');
        }
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::nl2br($subject, $useXhtml))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('nl2br() requires one or two arguments');
        }

        $strLit = JitStringArg::compileTimeLiteral($args[0]);
        $flagLit = 2 === $argc ? JitStringArg::compileTimeLiteral($args[1]) : null;
        if (null !== $strLit && (1 === $argc || null !== $flagLit)) {
            $useXhtml = true;
            if (null !== $flagLit) {
                // Same convert_to_boolean string rule as VmMath::parseBoolBuiltinArg (#4293).
                $useXhtml = '' !== $flagLit && '0' !== $flagLit;
            }

            return $context->builder->load(
                $context->constantStringFromString(VmString::nl2br($strLit, $useXhtml))
            );
        }

        $str = JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'nl2br', 0, 'string');
        $i8 = $context->getTypeFromString('int8');
        $useXhtmlI8 = $i8->constInt(1, false);
        if (2 === $argc) {
            $useXhtmlI8 = $context->builder->zExt(
                JitBoolArg::lowerCoerceZParamBool($context, $args[1], 'nl2br', 'use_xhtml', 2),
                $i8
            );
        }

        StringNl2br::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__string__nl2br'),
            $str,
            $useXhtmlI8
        );
    }

    /** Z_PARAM_STR — null TypeError on 8.4 forward profile (#19284, ext/standard/string.c). */
    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'nl2br', $paramName)->toString();
        }

        return VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[$argIndex],
            'nl2br',
            $argIndex,
            $paramName
        );
    }

}
