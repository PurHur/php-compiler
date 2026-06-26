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
        $excludeDeprecated = VmReflection::parseExcludeDeprecatedArg($frame, 'get_declared_interfaces');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(
            VmReflection::declaredInterfacesTable(
                VmReflection::requireContext($frame),
                $excludeDeprecated
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $literal = GetDeclaredExcludeDeprecatedJit::parseLiteral($context, $args, 'get_declared_interfaces');

        return JitGetDeclaredInterfaces::invoke($context, $literal ?? false);
    }
}
