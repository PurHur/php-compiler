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
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * strncasecmp() for two strings and an integer length (subset of PHP; LLVM via libc).
 */
final class strncasecmp extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'strncasecmp', 3);
        $a = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'strncasecmp', 0, 'string1');
        $b = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'strncasecmp', 1, 'string2');
        if (null === $frame->returnVar) {
            return;
        }
        $len = VmMath::parseIntBuiltinArg($frame->calledArgs[2], 'strncasecmp', 3, 'length');
        $frame->returnVar->int(VmString::strncasecmp($a, $b, $len));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireExactJitArgCount($context, $args, 'strncasecmp', 3)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        $p0 = $this->stringDataPtr($context, JitStringBuiltinArg::lower($context, $args[0], 'strncasecmp', 0, 'string1'));
        $p1 = $this->stringDataPtr($context, JitStringBuiltinArg::lower($context, $args[1], 'strncasecmp', 1, 'string2'));
        $length = $context->builder->zExt(
            $context->builder->trunc(
                JitLongArg::lower($context, $args[2], 'strncasecmp() length'),
                $context->getTypeFromString('int32')
            ),
            $context->getTypeFromString('size_t')
        );
        $raw = $context->builder->call($context->lookupFunction('strncasecmp'), $p0, $p1, $length);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($raw, $i64);
    }
}
