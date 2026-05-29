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
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('gc_collect_cycles() requires VM context');
        }
        $frame->returnVar->int(CycleCollector::collect($ctx));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('gc_collect_cycles() is not implemented for JIT in this compiler build');
    }
}
