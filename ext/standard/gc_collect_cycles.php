<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\CycleCollector;
use PHPLLVM\Value;

/**
 * gc_collect_cycles() — run VM cycle collector (Zend/zend_gc.c parity subset, #3113).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/info.c PHP_FUNCTION(gc_collect_cycles)
 */
final class gc_collect_cycles extends Internal
{
    public function __construct()
    {
        parent::__construct('gc_collect_cycles');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('gc_collect_cycles() takes no arguments');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('gc_collect_cycles() requires VM context');
        }
        $collected = CycleCollector::collect($ctx);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($collected);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('gc_collect_cycles() takes no arguments');
        }

        return JitGcCollectCycles::invoke($context);
    }
}
