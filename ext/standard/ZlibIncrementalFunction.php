<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** VM-only incremental zlib builtins (ext/zlib/zlib.c; issue #4656). */
abstract class ZlibIncrementalFunction extends Internal
{
    final protected function requireArgCountBetween(int $argc, int $min, int $max): void
    {
        if ($argc < $min) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at least %d arguments, %d given',
                $this->name,
                $min,
                $argc
            ));
        }
        if ($argc > $max) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects at most %d arguments, %d given',
                $this->name,
                $max,
                $argc
            ));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not lowered for JIT/AOT in this compiler build');
    }
}
