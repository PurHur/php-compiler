<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** unitenum_exists() — whether a pure (non-backed) user enum is registered (#6884). */
final class unitenum_exists_ extends Internal
{
    public function __construct()
    {
        parent::__construct('unitenum_exists');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('unitenum_exists() requires exactly one argument in this compiler build');
        }
        $ctx = VmReflection::requireContext($frame);
        $name = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'unitenum_exists', 0, 'enum');
        $exists = VmReflection::unitEnumExists($ctx, $name);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($exists);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('unitenum_exists() requires exactly one argument in this compiler build');
        }
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            return ReflectionBuiltinHelper::unitEnumExistsLiteral($context, $literal);
        }

        return JitUnitEnumExists::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'unitenum_exists', 0, 'enum')
        );
    }
}
