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
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * decbin() — integer to binary string (php-src ext/standard/math.c; #4211 float truncation).
 */
final class decbin extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/math.c — ArgumentCountError (#30535).
        $this->requireExactArgCount($frame, 'decbin', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $num = VmMath::parseChrCodepointForFrame(
            $frame,
            0,
            'decbin',
            1,
            'num'
        );
        $frame->returnVar->string(\decbin($num));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        // Catchable ArgumentCountError (AOT/JIT) — #30535.
        if (!$this->requireExactJitArgCount($context, $args, 'decbin', 1)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $num = JitChr::lowerZParamLongArg($context, $args[0], 'decbin', 1, 'num');

        return $this->formatToString($context, $num, '%b');
    }

    private function formatToString(Context $context, Value $value, string $format): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $bufSize = $sizeT->constInt(64, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString($format),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $value
        );
        $len = $context->builder->zExt($written, $i64);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        return $str;
    }
}
