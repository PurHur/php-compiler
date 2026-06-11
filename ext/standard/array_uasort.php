<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_uasort() — Zend alias of uasort() (ext/standard/array.c php_array_uasort; issue #5649).
 */
final class array_uasort extends Internal
{
    private uasort_ $delegate;

    public function __construct()
    {
        parent::__construct('array_uasort');
        $this->delegate = new uasort_();
    }

    public function execute(Frame $frame): void
    {
        $this->delegate->execute($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return $this->delegate->call($context, ...$args);
    }
}
