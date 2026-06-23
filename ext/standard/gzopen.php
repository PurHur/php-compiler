<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** gzopen() — zlib stream open (ext/zlib/zlib.c parity, #6168). */
final class gzopen extends Internal
{
    public function __construct()
    {
        parent::__construct('gzopen');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('gzopen() expects two or three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'gzopen', 0, 'filename');
        $mode = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'gzopen', 1, 'mode');
        $useIncludePath = 0;
        if (3 === $argc) {
            $useIncludePath = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2]->resolveIndirect(),
                'gzopen',
                3,
                'use_include_path'
            );
        }
        $handle = VmGzStream::gzopen($filename, $mode, $useIncludePath);
        if (false === $handle) {
            VmStreamOpenFailure::warnFailedToOpen($frame, 'gzopen', $filename);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->streamHandle($handle, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('gzopen() expects two or three arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $useIncludePath = $i64->constInt(0, false);
        if (3 === $argc) {
            $useIncludePath = JitLongArg::lower($context, $args[2], 'gzopen', 3, 'use_include_path');
        }

        return JitGzopen::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'gzopen', 0, 'filename'),
            JitStringBuiltinArg::lower($context, $args[1], 'gzopen', 1, 'mode'),
            $useIncludePath
        );
    }
}
