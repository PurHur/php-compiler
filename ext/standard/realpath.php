<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** realpath() — canonical path when the target exists (VM + JIT: VmString via RealpathJitHelper #15323). */
final class realpath extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src filestat.c / file.stub.php — exactly 1 (#30544).
        $this->requireExactArgCount($frame, 'realpath', 1);
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
        // Catchable ArgumentCountError under AOT try/catch (#30544 / peer #30523).
        if (!$this->requireExactJitArgCount($context, $args, 'realpath', 1)) {
            $i64 = $context->getTypeFromString('int64');
            $i8p = $context->getTypeFromString('int8*');

            return $context->builder->call(
                $context->lookupFunction('__string__init'),
                $i64->constInt(0, false),
                $context->builder->pointerCast($context->constantFromString(''), $i8p)
            );
        }

        $path = JitFilestatArg::lowerFilename($context, $args[0], 'realpath');

        return JitRealpath::resolve($context, $path);
    }
}
