<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_declared_classes() — list registered classes (issue #3128).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_declared_classes)
 */
final class get_declared_classes_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_declared_classes');
    }

    public function execute(Frame $frame): void
    {
        $excludeDeprecated = VmReflection::parseExcludeDeprecatedArg($frame, 'get_declared_classes');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(
            VmReflection::declaredClassesTable(
                VmReflection::requireContext($frame),
                $excludeDeprecated
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $literal = GetDeclaredExcludeDeprecatedJit::parseLiteral($context, $args, 'get_declared_classes');

        return JitGetDeclaredClasses::invoke($context, $literal ?? false);
    }
}
