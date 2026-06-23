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
 * str_contains() for two strings (subset of PHP 8).
 */
final class str_contains extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'str_contains', 2);
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'str_contains', 'haystack', 0);
        $haystackStr = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'str_contains',
            0,
            'haystack'
        );
        $needleStr = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'str_contains',
            1,
            'needle'
        );
        BuiltinExecute::writeReturn(
            $frame,
            static function (Variable $ret) use ($haystackStr, $needleStr): void {
                if ('' === $needleStr) {
                    $ret->bool(true);

                    return;
                }
                $ret->bool(false !== VmString::strpos($haystackStr, $needleStr));
            }
        );
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireExactJitArgCount($context, $args, 'str_contains', 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        JitInternalStrictArg::rejectNullString($context, $args[0], 'str_contains', 'haystack', 1);
        $hay = JitStringBuiltinArg::lower($context, $args[0], 'str_contains', 0, 'haystack');
        $needle = JitStringBuiltinArg::lower($context, $args[1], 'str_contains', 1, 'needle');

        return JitStringSearch::contains($context, $hay, $needle);
    }
}
