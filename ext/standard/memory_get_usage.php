<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** memory_get_usage() — current memory usage bytes (issue #3134). */
final class memory_get_usage extends Internal
{
    public function __construct()
    {
        parent::__construct('memory_get_usage');
    }

    public function execute(Frame $frame): void
    {
        $usage = VmMemory::getUsage(self::resolveRealUsage($frame));
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int($usage);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \ArgumentCountError(
                'memory_get_usage() expects at most 1 argument, '.\count($args).' given'
            );
        }

        return JitMemory::getUsage($context, $args[0] ?? null);
    }

    private static function resolveRealUsage(Frame $frame): bool
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'memory_get_usage() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (0 === $argc) {
            return false;
        }
        return VmMemory::resolveUsageArg($frame->calledArgs[0], 'memory_get_usage');
    }
}
