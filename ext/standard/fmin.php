<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * fmin() — variadic IEEE float minimum (PHP 8.4, ext/standard/math.c zend_fmin).
 */
final class fmin extends Internal
{
    private const FUNCTION = 'fmin';

    public function __construct()
    {
        parent::__construct(self::FUNCTION);
    }

    public function execute(Frame $frame): void
    {
        VmFminMax::fmin($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFminMax::invoke($context, true, ...$args);
    }
}
