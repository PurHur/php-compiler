<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * gc_mem_caches() — release VM allocator caches (ext/standard/php_gc.c parity, #3280).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/php_gc.c PHP_FUNCTION(gc_mem_caches)
 */
final class gc_mem_caches extends Internal
{
    public function __construct()
    {
        parent::__construct('gc_mem_caches');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError('gc_mem_caches() expects exactly 0 arguments, '.\count($frame->calledArgs).' given');
        }
        VmGcStatus::memCaches();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('gc_mem_caches() is VM-only in this compiler build (issue #3280 phase 1)');
    }
}
