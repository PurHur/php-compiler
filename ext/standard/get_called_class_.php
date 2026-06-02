<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_called_class() — late-static call-site class name (issue #3218).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_called_class)
 */
final class get_called_class_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_called_class');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('get_called_class() takes no arguments in this compiler build');
        }
        $calledClass = VmReflection::getCalledClass($frame);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string($calledClass);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('get_called_class() is not supported in JIT in this compiler build; use bin/vm.php');
    }
}
