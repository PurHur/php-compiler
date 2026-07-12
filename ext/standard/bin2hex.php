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
use PHPCompiler\JIT\Builtin\StringBin2hex;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPLLVM\Value;

/**
 * bin2hex() for string arguments (subset of PHP).
 *
 * VM: {@see VmString::bin2hex()}; JIT/AOT: {@see StringBin2hex} + {@see Bin2hexJitHelper}.
 */
final class bin2hex extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('bin2hex() requires exactly one argument');
        }
        $data = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[0], 'bin2hex', 0, 'string');
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($data): void {
            $ret->string(VmString::bin2hex($data));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('bin2hex() requires exactly one argument');
        }

        StringBin2hex::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_bin2hex'),
            JitStringBuiltinArg::lowerTypedString($context, $args[0], 'bin2hex', 0, 'string')
        );
    }
}
