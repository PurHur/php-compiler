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
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not lowered for JIT/AOT in this compiler build');
    }
}
