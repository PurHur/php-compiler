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
 * gc_enabled() — whether cyclic GC is enabled (ext/standard/php_gc.c parity, #3209).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/php_gc.c PHP_FUNCTION(gc_enabled)
 */
final class gc_enabled extends Internal
{
    public function __construct()
    {
        parent::__construct('gc_enabled');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('gc_enabled() takes no arguments');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(CycleCollector::isEnabled());
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('gc_enabled() is not implemented for JIT in this compiler build');
    }
}
