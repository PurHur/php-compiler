<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stream_resolve_include_path() — include_path lookup (ext/standard/streams.c; #6051). */
final class stream_resolve_include_path extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_resolve_include_path');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'stream_resolve_include_path() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'stream_resolve_include_path',
            0,
            'filename'
        );
        $resolved = VmFs::resolveIncludePath($filename);
        if (false === $resolved) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($resolved);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                'stream_resolve_include_path() expects exactly 1 argument, '.\count($args).' given'
            );
        }
        $filename = JitStringBuiltinArg::lower(
            $context,
            $args[0],
            'stream_resolve_include_path',
            0,
            'filename'
        );

        return JitResolveIncludePath::invoke($context, $filename);
    }
}
