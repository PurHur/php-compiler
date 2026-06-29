<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** realpath() — canonical path when the target exists (VM: VmString; JIT: libc via JitRealpath). */
final class realpath extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('realpath() requires exactly one argument');
        }
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'realpath', 'path');
        if (null === $frame->returnVar) {
            return;
        }
        $resolved = VmString::realpath($path);
        if (false === $resolved) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($resolved);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('realpath() requires exactly one argument');
        }

        $path = JitFilestatArg::lowerFilename($context, $args[0], 'realpath');

        return JitRealpath::resolve($context, $path);
    }
}
