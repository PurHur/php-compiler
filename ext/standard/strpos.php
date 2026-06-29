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
use PHPLLVM\Value;

/**
 * strpos() for two strings (subset of PHP; non-empty needle, Zend offset window).
 */
final class strpos extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('strpos() requires two or three arguments');
        }
        $haystackStr = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'strpos', 0, 'haystack');
        $needleStr = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'strpos', 1, 'needle');
        if (null === $frame->returnVar) {
            return;
        }
        $offset = 0;
        if (3 === $argc) {
            $offset = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'strpos', 3, 'offset');
        }
        $result = VmString::strpos($haystackStr, $needleStr, $offset);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($result);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('strpos() requires two or three arguments');
        }
        $hay = JitStringBuiltinArg::lower($context, $args[0], 'strpos', 0, 'haystack');
        $needle = JitStringBuiltinArg::lower($context, $args[1], 'strpos', 1, 'needle');
        $offset = 3 === $argc
            ? JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'strpos', 3, 'offset')
            : null;

        return JitStrpos::find($context, $hay, $needle, $offset);
    }

}
