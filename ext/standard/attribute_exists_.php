<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * attribute_exists() — probe whether a class declares a given attribute (#6468).
 *
 * php-src: ext/reflection/php_reflection.c — PHP_FUNCTION(attribute_exists)
 */
final class attribute_exists_ extends Internal
{
    public function __construct()
    {
        parent::__construct('attribute_exists');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('attribute_exists() requires exactly two arguments');
        }
        $ctx = VmReflection::requireContext($frame);
        $class = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'attribute_exists', 0, 'class');
        $attribute = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'attribute_exists', 1, 'attribute');
        $exists = VmReflection::attributeExists($ctx, $class, $attribute);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($exists);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('attribute_exists() requires exactly two arguments');
        }

        return JitAttributeExists::invoke($context, $args[0], $args[1]);
    }
}
