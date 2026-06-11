<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_uksort() — Zend alias of uksort() (ext/standard/array.c php_array_uksort; issue #5649).
 */
final class array_uksort extends Internal
{
    private uksort_ $delegate;

    public function __construct()
    {
        parent::__construct('array_uksort');
        $this->delegate = new uksort_();
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
