<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** memory_get_peak_usage() — peak memory usage bytes (issue #3134). */
final class memory_get_peak_usage extends Internal
{
    public function __construct()
    {
        parent::__construct('memory_get_peak_usage');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmMemory::getPeakUsage(self::resolveRealUsage($frame)));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \ArgumentCountError(
                'memory_get_peak_usage() expects at most 1 argument, '.\count($args).' given'
            );
        }

        return JitMemory::getPeakUsage($context, $args[0] ?? null);
    }

    private static function resolveRealUsage(Frame $frame): bool
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'memory_get_peak_usage() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (0 === $argc) {
            return false;
        }
        return VmMemory::resolveUsageArg($frame->calledArgs[0], 'memory_get_peak_usage');
    }
}
