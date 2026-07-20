<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * fadd() — IEEE-754 float addition (PHP 8.4, ext/standard/math.c / zend_fadd).
 */
final class fadd extends Internal
{
    private const FUNCTION = 'fadd';

    public function __construct()
    {
        parent::__construct(self::FUNCTION);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('%s() expects exactly 2 arguments, %d given', self::FUNCTION, $argc)
            );
        }
        $num1 = VmMath::parseForwardProfileStrictDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            self::FUNCTION,
            1,
            'num1',
            $frame
        );
        $num2 = VmMath::parseForwardProfileStrictDoubleBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            self::FUNCTION,
            2,
            'num2',
            $frame
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmMath::fadd($num1, $num2));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('%s() expects exactly 2 arguments, %d given', self::FUNCTION, \count($args))
            );
        }
        [$left, $right] = JitFdiv::lowerOperands(
            $context,
            $args[0],
            $args[1],
            self::FUNCTION,
            'num1',
            'num2',
            'float',
            true
        );

        return $context->builder->fadd($left, $right);
    }
}
