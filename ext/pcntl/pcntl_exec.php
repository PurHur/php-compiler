<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** php-src ext/pcntl/pcntl.c PHP_FUNCTION(pcntl_exec) — issue #19565. */
final class pcntl_exec extends Internal
{
    public function __construct()
    {
        parent::__construct('pcntl_exec');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                'pcntl_exec() expects at least 1 argument and at most 3, '.$argc.' given'
            );
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pcntl_exec', 0, 'path');
        $args = [];
        if ($argc >= 2) {
            $args = VmPcntlArg::parseStringList($frame->calledArgs[1], 'pcntl_exec', 1, 'args');
        }
        $env = [];
        if ($argc >= 3) {
            $env = VmPcntlArg::parseEnvMap($frame->calledArgs[2], 'pcntl_exec', 2, 'env_vars');
        }
        $ok = VmPcntl::exec($path, $args, $env);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('pcntl_exec() is not implemented for JIT in this compiler build (issue #19565)');
    }
}
