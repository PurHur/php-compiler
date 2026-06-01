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
 * gc_disable() — disable cyclic garbage collection (ext/standard/php_gc.c parity, #3209).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/php_gc.c PHP_FUNCTION(gc_disable)
 */
final class gc_disable extends Internal
{
    public function __construct()
    {
        parent::__construct('gc_disable');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('gc_disable() takes no arguments');
        }
        CycleCollector::disable();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('gc_disable() is not implemented for JIT in this compiler build');
    }
}
