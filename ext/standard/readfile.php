<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringReadfile;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * readfile() — stream file bytes to stdout; returns bytes read or false (php-src php_stream_passthru).
 *
 * NestedJIT leaf: {@see JitReadfile} → {@see JitReadfileLibc} so
 * `@readfile` does not re-enter {@see ReadfileJitHelper} (#29915 / #29833).
 *
 * Optional `$use_include_path` / `$context` (arity 1..3) — #30582; php-src ext/standard/file.c.
 */
final class readfile extends Internal
{
    public function __construct()
    {
        parent::__construct('readfile');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 1..3 — #30582.
        $this->requireArgCountRange($frame, 'readfile', 1, 3);
        $path = VmStreamPath::coerceNonEmptyPathArgForFrame($frame, 0, 'readfile', 'filename');
        if (null === $frame->returnVar) {
            return;
        }

        $useIncludePath = false;
        if (isset($frame->calledArgs[1])) {
            $useIncludePath = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[1],
                'readfile',
                2,
                'use_include_path'
            );
        }

        if (isset($frame->calledArgs[2])) {
            VmStreamContext::validateOptionalContextArg(
                $frame->calledArgs[2],
                'readfile',
                3
            );
        }

        if ($useIncludePath) {
            $resolved = VmFs::resolveIncludePath($path);
            if (false !== $resolved) {
                $path = $resolved;
            }
        }

        $result = VmFs::readfile($path);
        if (false === $result) {
            VmStreamOpenFailure::warnFailedToOpen($frame, 'readfile', $path);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30582).
        if (!$this->requireArgCountRangeJit($context, $args, 'readfile', 1, 3)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        StringReadfile::ensureLinked($context);
        $path = JitStreamPath::lowerNonEmptyPath($context, $args[0], 'readfile');
        if (isset($args[1])) {
            // Type-check / coerce bool; true include-path resolution remains VM-side (#30582).
            JitBoolArg::lowerCoerceZParamBool($context, $args[1], 'readfile', 'use_include_path', 2);
        }
        if (isset($args[2])) {
            JitStreamContextOptionalArg::validate($context, $args[2], 'readfile', 3);
        }

        return JitReadfile::invoke($context, $path);
    }
}
