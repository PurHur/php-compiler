<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ObjectReadonlySupport;
use PHPLLVM\Value;

/**
 * readonly(object $object): void — mark a dynamic object readonly (PHP 8.4, #6485).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.c PHP_FUNCTION(readonly)
 */
final class readonly_ extends Internal
{
    public function __construct()
    {
        parent::__construct('readonly');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'readonly() expects exactly 1 argument, '.$argc.' given'
            );
        }
        ObjectReadonlySupport::markDynamicReadonly($frame->calledArgs[0]);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitReadonly::invoke($context, ...$args);
    }
}
