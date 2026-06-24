<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * readfile() — stream file bytes to stdout; returns bytes read or false (php-src php_stream_passthru).
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
        $path = VmStreamPath::coerceNonEmptyPathArg($frame->calledArgs[0], 'readfile');
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
        $path = JitStreamPath::lowerNonEmptyPath($context, $args[0], 'readfile');

        return JitReadfile::invoke($context, $path);
    }
}
