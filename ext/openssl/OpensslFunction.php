<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for openssl builtins (php-src ext/openssl/openssl.c; issue #7000).
 *
 * Phase 0 skeleton: register symbols; crypto in #3324.
 */
abstract class OpensslFunction extends Internal
{
    public function execute(Frame $frame): void
    {
        throw new \LogicException($this->getName().'() is not implemented in this compiler build (issue #3324)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not implemented for JIT in this compiler build (issue #3324)');
    }
}
