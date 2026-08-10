<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringReadfile;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * readfile() — stream file bytes to stdout; returns bytes read or false (php-src php_stream_passthru).
 *
 * NestedJIT leaf: {@see JitReadfile} → {@see JitReadfileLibc} so
 * `@readfile` does not re-enter {@see ReadfileJitHelper} (#29915 / #29833).
 */
final class readfile extends Internal
{
    public function __construct()
    {
        parent::__construct('readfile');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('readfile() requires exactly one argument in this compiler build');
        }
        $path = VmStreamPath::coerceNonEmptyPathArgForFrame($frame, 0, 'readfile', 'filename');
        if (null === $frame->returnVar) {
            return;
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
        if (1 !== \count($args)) {
            throw new \LogicException('readfile() requires exactly one argument in this compiler build');
        }
        StringReadfile::ensureLinked($context);
        $path = JitStreamPath::lowerNonEmptyPath($context, $args[0], 'readfile');

        return JitReadfile::invoke($context, $path);
    }
}
