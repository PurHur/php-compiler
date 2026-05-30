<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * __halt_compiler() — compile-time halt marker; runtime call is fatal (Zend zend_compile.c, issue #3479).
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_compile.c zend_compile_halt_compiler()
 */
final class halt_compiler_ extends Internal
{
    public function __construct()
    {
        parent::__construct('__halt_compiler');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('__halt_compiler() takes no arguments');
        }

        throw new \CompileError('__HALT_COMPILER() can only be used from the outermost scope');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('__halt_compiler() takes no arguments');
        }

        throw new \CompileError('__HALT_COMPILER() can only be used from the outermost scope');
    }
}
