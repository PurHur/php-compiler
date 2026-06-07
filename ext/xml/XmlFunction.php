<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for xml builtins (php-src ext/xml/xml.c; issue #7406).
 *
 * Phase 0 skeleton: register symbols; SAX/expat parity in #3494.
 */
abstract class XmlFunction extends Internal
{
    public function execute(Frame $frame): void
    {
        throw new \Error($this->getName().'() is not implemented in this compiler build (issue #3494)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT in this compiler build (issue #3494)');
    }
}
