<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * memory_reset_peak_usage() — reset peak counters to current usage (issue #5539).
 *
 * php-src: ext/standard/info.c — PHP_FUNCTION(memory_reset_peak_usage);
 * Zend/zend_alloc.c — zend_reset_peak_memory_usage
 */
final class memory_reset_peak_usage extends Internal
{
    public function __construct()
    {
        parent::__construct('memory_reset_peak_usage');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            VmMemory::resetPeakUsage(self::resolveRealUsage($frame));

            return;
        }
        VmMemory::resetPeakUsage(self::resolveRealUsage($frame));
        $frame->returnVar->null();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \ArgumentCountError(
                'memory_reset_peak_usage() takes at most 1 argument, '.\count($args).' given'
            );
        }

        return JitMemory::resetPeakUsage($context, $args[0] ?? null);
    }

    private static function resolveRealUsage(Frame $frame): bool
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'memory_reset_peak_usage() takes at most 1 argument, '.$argc.' given'
            );
        }
        if (0 === $argc) {
            return false;
        }

        return VmMemory::resolveUsageArg($frame->calledArgs[0], 'memory_reset_peak_usage');
    }
}
