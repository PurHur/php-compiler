<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
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
        [$categorize, $category] = self::parseArgs($frame);
        if (null === $frame->returnVar) {
            return;
        }
        if (null !== $category) {
            $frame->returnVar->array(VmConstants::getDefinedConstantsForCategory($ctx, $category));
        } else {
            $frame->returnVar->array(VmConstants::getDefinedConstants($ctx, $categorize));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitGetDefinedConstants::invoke($context, $args[0] ?? null, $args[1] ?? null);
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private static function parseArgs(Frame $frame): array
    {
        $argc = \count($frame->calledArgs);
        if (!CompilerVersion::supportsGetDefinedConstantsCategory()) {
            if ($argc > 1) {
                throw new \LogicException('get_defined_constants() accepts at most one argument');
            }
            $categorize = false;
            if (1 === $argc) {
                $categorize = VmMath::parseBoolBuiltinArg(
                    $frame->calledArgs[0],
                    'get_defined_constants',
                    1,
                    'categorize'
                );
            }

            return [$categorize, null];
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(
                \sprintf('get_defined_constants() expects at most 2 arguments, %d given', $argc)
            );
        }

        $categorize = false;
        $category = null;
        if (isset($frame->calledArgs[0])) {
            $arg0 = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_STRING === $arg0->type) {
                $category = VmReflection::stringArg(
                    $frame->calledArgs[0],
                    'get_defined_constants() category',
                    0
                );
            } else {
                $categorize = VmMath::parseBoolBuiltinArg(
                    $frame->calledArgs[0],
                    'get_defined_constants',
                    1,
                    'categorize'
                );
            }
        }
        if (isset($frame->calledArgs[1])) {
            if (null !== $category) {
                throw new \ArgumentCountError(
                    'get_defined_constants() expects at most 2 arguments, 2 given'
                );
            }
            $category = VmReflection::stringArg(
                $frame->calledArgs[1],
                'get_defined_constants() category',
                1
            );
        }

        return [$categorize, $category];
    }
}
