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
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * str_starts_with() for two strings (subset of PHP 8).
 */
final class str_starts_with extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'str_starts_with', 2);
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'str_starts_with', 'haystack', 0, $frame);
        InternalStrictArg::rejectNullString($frame->calledArgs[1], 'str_starts_with', 'needle', 1, $frame);
        InternalStrictArg::requireString($frame, 0, 'str_starts_with', 'haystack');
        InternalStrictArg::requireString($frame, 1, 'str_starts_with', 'needle');
        $haystackStr = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'str_starts_with',
            0,
            'haystack'
        );
        $needleStr = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'str_starts_with',
            1,
            'needle'
        );
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool(VmString::startsWith($haystackStr, $needleStr))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'str_starts_with', 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        JitInternalStrictArg::rejectNullString($context, $args[0], 'str_starts_with', 'haystack', 1);
        JitInternalStrictArg::rejectNullString($context, $args[1], 'str_starts_with', 'needle', 2);
        $hay = JitStringBuiltinArg::lowerCoercible($context, $args[0], 'str_starts_with', 0, 'haystack');
        $needle = JitStringBuiltinArg::lowerCoercible($context, $args[1], 'str_starts_with', 1, 'needle');

        return JitStringSearch::startsWith($context, $hay, $needle);
    }
}
