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
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * str_split() for strings (subset of PHP; native LLVM in JIT).
 */
final class str_split extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#23164).
        $this->requireArgCountRange($frame, 'str_split', 1, 2);
        $argc = \count($frame->calledArgs);
        $string = VmString::trimFamilyStringArgForFrame($frame, 0, 'str_split', 0, 'string');
        $length = 1;
        if (2 === $argc) {
            $length = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'str_split', 2, 'length');
        }
        $parts = VmString::strSplit($string, $length);
        if (null === $frame->returnVar) {
            return;
        }
        $out = new HashTable();
        foreach ($parts as $part) {
            $stored = new Variable();
            $stored->string($part);
            $out->append($stored);
        }
        $frame->returnVar->array($out);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'str_split', 1, 2)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }
        $argc = \count($args);
        $literal = $args[0]->compileTimeString ?? null;
        $skipLiteralFastPath = 2 === $argc
            && $context->callerStrictTypes
            && JITVariable::TYPE_NATIVE_DOUBLE === $args[1]->type;
        if (null !== $literal && !$skipLiteralFastPath) {
            $chunkLenInt = 1;
            if (2 === $argc) {
                $chunkLenInt = JitStrSplit::compileTimeLong($context, $args[1]);
            }

            return JitStrSplit::buildPackedStrings($context, $literal, $chunkLenInt);
        }
        $chunkLen = $context->constantFromInteger(1, 'int64');
        if (2 === $argc) {
            $chunkLen = JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[1], 'str_split', 2, 'length');
            JitStrSplit::emitRuntimeLengthGuard($context, $chunkLen);
        }

        return JitStrSplit::split(
            $context,
            self::jitStringArg($context, $args[0]),
            $chunkLen
        );
    }

    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'str_split',
                0,
                'string'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'str_split',
            0,
            'string'
        );
    }
}
