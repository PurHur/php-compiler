<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** readgzfile() — output gzip file to stdout (ext/zlib/zlib.c parity, #4657). */
final class readgzfile extends Internal
{
    public function __construct()
    {
        parent::__construct('readgzfile');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/zlib/zlib.c — ArgumentCountError (#30829).
        $this->requireArgCountRange($frame, 'readgzfile', 1, 2);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmZlibArg::resolveFilenameString($frame, 'readgzfile');
        $useIncludePath = 0;
        if (2 === $argc) {
            $useIncludePath = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1]->resolveIndirect(),
                'readgzfile',
                2,
                'use_include_path'
            );
        }
        $result = VmGzStream::readgzfile($filename, $useIncludePath);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'readgzfile', 1, 2)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $argc = \count($args);
        $i64 = $context->getTypeFromString('int64');
        $useIncludePath = $i64->constInt(0, false);
        if (2 === $argc) {
            $useIncludePath = JitLongArg::lower($context, $args[1], 'readgzfile', 2, 'use_include_path');
        }

        return JitReadgzfile::invoke(
            $context,
            VmZlibArg::jitFilenamePath($context, $args[0], 'readgzfile', 0),
            $useIncludePath
        );
    }
}
