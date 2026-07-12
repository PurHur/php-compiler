<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** VM-only procedural zip builtins (ext/zip/php_zip.c; #6370). */
abstract class ZipProceduralFunction extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not lowered for JIT/AOT in this compiler build (#6370)');
    }
}
