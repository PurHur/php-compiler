<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mhash_count() — number of registered mhash algorithms (php-src ext/hash/hash.c; #14975). */
final class mhash_count extends Internal
{
    public function __construct()
    {
        parent::__construct('mhash_count');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError(
                \sprintf('mhash_count() expects exactly 0 arguments, %d given', $argc)
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(MhashRegistry::count());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT in this compiler build (issue #14975)');
    }
}
