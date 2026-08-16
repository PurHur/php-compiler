<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** fopen() — VM via VmFs; JIT/AOT via __compiler_fopen (issue #1117, #11493). */
final class fopen extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'fopen() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(
                'fopen() expects at most 4 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $path = VmStreamPath::coerceNonEmptyPathArgForFrame($frame, 0, 'fopen', 'filename');
        $mode = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'fopen', 1, 'mode');

        $useIncludePath = false;
        if (isset($frame->calledArgs[2])) {
            $useIncludePath = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[2],
                'fopen',
                3,
                'use_include_path'
            );
        }

        if (isset($frame->calledArgs[3])) {
            $contextVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $contextVar->type) {
                VmStreamContext::requireRepresentation($contextVar, 'fopen', 4);
            }
        }

        if ($useIncludePath) {
            $resolved = VmFs::resolveIncludePath($path);
            if (false !== $resolved) {
                $path = $resolved;
            }
        }

        $handle = VmFs::fopen($path, $mode, $frame->vmContext);
        if (false === $handle) {
            VmStreamOpenFailure::warnFailedToOpen($frame, 'fopen', $path);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->streamHandle($handle, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'fopen() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(
                'fopen() expects at most 4 arguments, '.$argc.' given'
            );
        }

        return JitFopen::invoke(
            $context,
            JitStreamPath::lowerNonEmptyPath($context, $args[0], 'fopen', 0, 'filename'),
            JitStringBuiltinArg::lower($context, $args[1], 'fopen', 1, 'mode')
        );
    }
}
