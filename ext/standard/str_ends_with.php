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
use PHPCompiler\JIT\Builtin\StringStrContains;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * str_ends_with() for two strings (subset of PHP 8).
 */
final class str_ends_with extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'str_ends_with', 2);
        InternalStrictArg::rejectNullString($frame->calledArgs[1], 'str_ends_with', 'needle', 1, $frame);
        $haystackStr = self::vmStringArg($frame, 0, 'haystack');
        $needleStr = self::vmStringArg($frame, 1, 'needle');
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->bool(VmString::endsWith($haystackStr, $needleStr))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'str_ends_with', 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        JitInternalStrictArg::rejectNullString($context, $args[1], 'str_ends_with', 'needle', 2);
        $hay = JitStringBuiltinArg::lowerCoercible($context, $args[0], 'str_ends_with', 0, 'haystack');
        $needle = JitStringBuiltinArg::lowerCoercible($context, $args[1], 'str_ends_with', 1, 'needle');

        return StringStrContains::invokeEndsWith($context, $hay, $needle);
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        return VmString::stringBuiltinArgForFrame($frame, $argIndex, 'str_ends_with', $argIndex, $paramName);
    }
}
