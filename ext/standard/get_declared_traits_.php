<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_declared_traits() — list registered traits (issue #3128).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_declared_traits)
 */
final class get_declared_traits_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_declared_traits');
    }

    public function execute(Frame $frame): void
    {
        $excludeDeprecated = VmReflection::parseExcludeDeprecatedArg($frame, 'get_declared_traits');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(
            VmReflection::declaredTraitsTable(
                VmReflection::requireContext($frame),
                $excludeDeprecated
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $literal = GetDeclaredExcludeDeprecatedJit::parseLiteral($context, $args, 'get_declared_traits');

        return JitGetDeclaredTraits::invoke($context, $literal ?? false);
    }
}
