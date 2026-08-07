<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** get_defined_constants() — runtime constant introspection (issue #3135). */
final class get_defined_constants_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_defined_constants');
    }

    public function execute(Frame $frame): void
    {
        $ctx = VmReflection::requireContext($frame);
        $categorize = self::parseArgs($frame);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmConstants::getDefinedConstants($ctx, $categorize));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \ArgumentCountError(
                \sprintf('get_defined_constants() expects at most 1 argument, %d given', \count($args))
            );
        }

        return JitGetDefinedConstants::invoke($context, $args[0] ?? null);
    }

    private static function parseArgs(Frame $frame): bool
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                \sprintf('get_defined_constants() expects at most 1 argument, %d given', $argc)
            );
        }
        if (0 === $argc) {
            return false;
        }

        return VmMath::parseBoolBuiltinArg(
            $frame->calledArgs[0],
            'get_defined_constants',
            1,
            'categorize'
        );
    }
}
