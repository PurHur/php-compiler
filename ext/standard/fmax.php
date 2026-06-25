<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * fmax() — variadic IEEE float maximum (PHP 8.4, ext/standard/math.c zend_fmax).
 */
final class fmax extends Internal
{
    private const FUNCTION = 'fmax';

    public function __construct()
    {
        parent::__construct(self::FUNCTION);
    }

    public function execute(Frame $frame): void
    {
        VmFminMax::fmax($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFminMax::invoke($context, false, ...$args);
    }
}
