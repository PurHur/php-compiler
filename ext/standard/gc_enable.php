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
 * gc_enable() — enable cyclic garbage collection (ext/standard/php_gc.c parity, #3209).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/php_gc.c PHP_FUNCTION(gc_enable)
 */
final class gc_enable extends Internal
{
    public function __construct()
    {
        parent::__construct('gc_enable');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('gc_enable() takes no arguments');
        }
        CycleCollector::enable();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('gc_enable() is not implemented for JIT in this compiler build');
    }
}
