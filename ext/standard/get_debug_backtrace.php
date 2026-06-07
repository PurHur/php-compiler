<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_debug_backtrace() — Zend alias of debug_backtrace() (ext/standard/basic_functions.c, #6802).
 *
 * @see debug_backtrace
 */
final class get_debug_backtrace extends Internal
{
    private debug_backtrace $delegate;

    public function __construct()
    {
        parent::__construct('get_debug_backtrace');
        $this->delegate = new debug_backtrace();
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
