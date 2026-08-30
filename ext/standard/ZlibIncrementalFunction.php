<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Incremental zlib builtins (ext/zlib/zlib.c; #4656). JIT/AOT: {@see JitZlibIncremental} (#35885). */
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
        return JitZlibIncremental::dispatch($context, $this->getName(), ...$args);
    }
}
