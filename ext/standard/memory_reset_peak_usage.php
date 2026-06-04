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
        self::assertNoExtraArgs($frame);
        VmMemory::resetPeakUsage(false);
        VmMemory::resetPeakUsage(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \ArgumentCountError(
                'memory_reset_peak_usage() takes no arguments, '.\count($args).' given'
            );
        }

        return JitMemory::resetPeakUsage($context);
    }

    private static function assertNoExtraArgs(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError(
                'memory_reset_peak_usage() takes no arguments, '.$argc.' given'
            );
        }
    }
}
