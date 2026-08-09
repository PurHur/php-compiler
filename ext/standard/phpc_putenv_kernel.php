<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal libc setenv mirror kernel for PutenvJitHelper (#23414).
 *
 * Single "NAME=value" (or "NAME" unset) assignment arg — NestedJIT leaf only
 * inside PutenvJitHelper::putenv (peer {@see GetenvLookupJitHelper} / StringRename #29090).
 * php-src: ext/standard/basic_functions.c — zif_putenv
 */
final class phpc_putenv_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_putenv_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \LogicException('phpc_putenv_kernel() expects exactly 1 argument, '.$argc.' given');
        }
        $assignment = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'phpc_putenv_kernel',
            0,
            'assignment'
        );
        @\putenv($assignment);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_putenv_kernel() expects exactly 1 argument');
        }
        $assignment = JitStringBuiltinArg::lower(
            $context,
            $args[0],
            'phpc_putenv_kernel',
            0,
            'assignment'
        );
        JitPutenvKernel::invoke($context, $assignment);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
