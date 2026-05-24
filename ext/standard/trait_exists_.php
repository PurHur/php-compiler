<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** trait_exists() — whether a user trait is registered (issue #1371). */
final class trait_exists_ extends Internal
{
    public function __construct()
    {
        parent::__construct('trait_exists');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1 || \count($frame->calledArgs) > 2) {
            throw new \LogicException('trait_exists() requires one or two arguments in this compiler build');
        }
        $ctx = VmReflection::requireContext($frame);
        $name = VmReflection::stringArg($frame->calledArgs[0], 'trait_exists() trait name');
        $exists = VmReflection::traitExists($ctx, $name);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($exists);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('trait_exists() requires one or two arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type && JITVariable::TYPE_VALUE !== $args[0]->type) {
            throw new \LogicException('trait_exists() trait name must be a string in this compiler build');
        }

        return JitTraitExists::invoke($context, $args[0]);
    }
}

