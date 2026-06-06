<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for curl builtins (php-src ext/curl/interface.c; issue #6999).
 *
 * Phase 0 skeleton: register symbols; libcurl I/O in #3325.
 */
abstract class CurlFunction extends Internal
{
    public function execute(Frame $frame): void
    {
        throw new \LogicException($this->getName().'() is not implemented in this compiler build (issue #3325)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not implemented for JIT in this compiler build (issue #3325)');
    }
}
