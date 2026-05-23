<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** chmod() — VM via VmFs; JIT/AOT via libc chmod(2). */
final class chmod_ extends Internal
{
    public function __construct()
    {
        parent::__construct('chmod');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('chmod() requires exactly two arguments in this compiler build');
        }
        $pathVar = $frame->calledArgs[0]->resolveIndirect();
        $modeVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $pathVar->type) {
            throw new \LogicException('chmod() filename must be a string in this compiler build');
        }
        if (Variable::TYPE_INTEGER !== $modeVar->type) {
            throw new \LogicException('chmod() permissions must be an integer in this compiler build');
        }
        $frame->returnVar->bool(VmFs::chmod($pathVar->toString(), $modeVar->toInt()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('chmod() requires exactly two arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('chmod() filename must be a string in this compiler build');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('chmod() permissions must be an integer in this compiler build');
        }
        $i32 = $context->getTypeFromString('int32');
        $mode = $context->builder->truncOrBitCast(
            $context->helper->loadValue($args[1]),
            $i32
        );

        return JitChmod::invoke($context, $context->helper->loadValue($args[0]), $mode);
    }
}
