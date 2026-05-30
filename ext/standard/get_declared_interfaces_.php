<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_declared_interfaces() — list registered interfaces (issue #3176).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_declared_interfaces)
 */
final class get_declared_interfaces_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_declared_interfaces');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('get_declared_interfaces() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(
            VmReflection::declaredInterfacesTable(VmReflection::requireContext($frame))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('get_declared_interfaces() takes no arguments');
        }

        return JitGetDeclaredInterfaces::invoke($context);
    }
}
