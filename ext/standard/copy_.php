<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** copy() — VM via VmFs; JIT/AOT via __compiler_copy (native fread/fwrite). */
final class copy_ extends Internal
{
    public function __construct()
    {
        parent::__construct('copy');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'copy() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(
                'copy() expects at most 3 arguments, '.$argc.' given'
            );
        }
        if (isset($frame->calledArgs[2])) {
            VmStreamContext::validateOptionalContextArg($frame->calledArgs[2], 'copy', 3);
        }
        $from = VmStreamPath::coerceNonEmptyPathArg($frame->calledArgs[0], 'copy', 0, 'from');
        $to = VmStreamPath::coerceNonEmptyPathArg($frame->calledArgs[1], 'copy', 1, 'to');
        if (VmStatPath::isDir($from)) {
            VmCopyFailure::warnDirectorySource($frame);
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(false);
            }

            return;
        }
        $ok = VmFs::copy($from, $to);
        if (!$ok) {
            VmStreamOpenFailure::warnFailedToOpen($frame, 'copy', $from);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'copy() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(
                'copy() expects at most 3 arguments, '.$argc.' given'
            );
        }
        if (isset($args[2])) {
            JitStreamContextOptionalArg::validate($context, $args[2], 'copy', 3);
        }
        $from = JitStreamPath::lowerNonEmptyPath($context, $args[0], 'copy', 0, 'from');
        $to = JitStreamPath::lowerNonEmptyPath($context, $args[1], 'copy', 1, 'to');

        return JitCopy::invoke($context, $from, $to);
    }
}
