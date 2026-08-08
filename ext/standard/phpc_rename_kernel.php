<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringRename;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal NestedJIT rename(2) leaf for RenameJitHelper (#19215, #29090).
 *
 * Resolvable under NestedJIT before Context::registerModule (whitelist).
 * LLVM body: {@see StringRename::invokeNestedLeaf} — module-local rename(2) decl.
 */
final class phpc_rename_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_rename_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \LogicException('phpc_rename_kernel() expects exactly 2 arguments, '.$argc.' given');
        }
        $from = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'phpc_rename_kernel', 0, 'from', $frame);
        $to = VmFilestatArg::coerceFilenameArg($frame->calledArgs[1], 'phpc_rename_kernel', 1, 'to', $frame);
        $ok = @\rename($from, $to);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('phpc_rename_kernel() expects exactly 2 arguments');
        }
        $from = JitStringBuiltinArg::lowerPath($context, $args[0], 'phpc_rename_kernel', 0, 'from');
        $to = JitStringBuiltinArg::lowerPath($context, $args[1], 'phpc_rename_kernel', 1, 'to');

        return StringRename::invokeNestedLeaf($context, $from, $to);
    }
}
