<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\SensitiveParamSupport;
use PHPCompiler\VM\Variable;

/** ReflectionParameter::getValue(array $args) — unwrap SensitiveParameterValue (#5127). */
final class ReflectionParameterGetValue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getValue');
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionParameter($frame, $frame->calledArgs[0]);
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionParameter::getValue() expects argument values');
        }
        $argsVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $argsVar->type) {
            throw new \TypeError(
                'ReflectionParameter::getValue(): Argument #1 ($args) must be of type array, '
                .ReflectionSupport::valueTypeLabelPublic($argsVar).' given'
            );
        }
        $paramName = ReflectionSupport::paramNameFromReflection($receiver);
        $found = $argsVar->toArray()->find($paramName);
        if (null === $found) {
            throw new \LogicException(
                'ReflectionParameter::getValue(): Argument values must include key '.$paramName
            );
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(SensitiveParamSupport::unwrapForReflection($found));
        }
    }
}
