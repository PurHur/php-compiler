<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
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
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError('gc_collect_cycles() expects exactly 0 arguments, '.$argc.' given');
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
        $argc = \count($args);
        if ($argc > 0) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'gc_collect_cycles() expects exactly 0 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitGcCollectCycles::invoke($context);
    }
}
