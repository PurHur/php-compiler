<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * memory_reset_peak_usage() — reset peak counters to current usage (issue #5539, #26104).
 *
 * php-src: ext/standard/info.c — PHP_FUNCTION(memory_reset_peak_usage) (ZEND_PARSE_PARAMETERS_NONE);
 * Zend/zend_alloc.c — zend_memory_reset_peak_usage (emalloc + real peaks).
 * stub: function memory_reset_peak_usage(): void {}
 */
final class memory_reset_peak_usage extends Internal
{
    public function __construct()
    {
        parent::__construct('memory_reset_peak_usage');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'memory_reset_peak_usage', 0);
        VmMemory::resetPeakUsage();
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitMemory::resetPeakUsage($context, ...$args);
    }
}
