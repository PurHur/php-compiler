<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_declared_enums() — list registered enums (issue #3538).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_declared_enums)
 */
final class get_declared_enums_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_declared_enums');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \LogicException('get_declared_enums() takes no arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(
            VmReflection::declaredEnumsTable(VmReflection::requireContext($frame))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 0) {
            throw new \LogicException('get_declared_enums() takes no arguments');
        }

        return JitGetDeclaredEnums::invoke($context);
    }
}
