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
 * @internal libc chdir(2) kernel for ChdirJitHelper (#21147).
 */
final class phpc_chdir_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_chdir_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \LogicException('phpc_chdir_kernel() expects exactly 1 argument, '.$argc.' given');
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'phpc_chdir_kernel', 0, 'directory', $frame);
        $ok = @\chdir($path);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_chdir_kernel() expects exactly 1 argument');
        }
        $path = JitStringBuiltinArg::lowerPath($context, $args[0], 'phpc_chdir_kernel', 0, 'directory');

        return JitChdirKernel::invoke($context, $path);
    }
}
