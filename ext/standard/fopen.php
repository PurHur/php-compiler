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
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(
                'fopen() expects at least 2 arguments, '.\max(0, $argc - 2).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $path = VmStreamPath::coerceNonEmptyPathArg($frame->calledArgs[0], 'fopen');
        $mode = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'fopen', 1, 'mode');

        $useIncludePath = false;
        if ($argc >= 3) {
            $useIncludePath = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[2],
                'fopen',
                3,
                'use_include_path'
            );
        }

        if ($argc >= 4) {
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
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(
                'fopen() expects at least 2 arguments, '.\max(0, $argc - 2).' given'
            );
        }

        return JitFopen::invoke(
            $context,
            JitStreamPath::lowerNonEmptyPath($context, $args[0], 'fopen'),
            JitStringBuiltinArg::lower($context, $args[1], 'fopen', 1, 'mode')
        );
    }
}
