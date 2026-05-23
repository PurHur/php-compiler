<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * is_subclass_of() — exact class match only until extends is implemented (issue #1219).
 */
final class is_subclass_of_ extends Internal
{
    public function __construct()
    {
        parent::__construct('is_subclass_of');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs) && 3 !== \count($frame->calledArgs)) {
            throw new \LogicException('is_subclass_of() requires two or three arguments');
        }
        $ctx = VmReflection::requireContext($frame);
        $parentName = VmReflection::stringArg($frame->calledArgs[1], 'is_subclass_of() parent class');
        $subject = $frame->calledArgs[0]->resolveIndirect();
        $matches = false;
        if (Variable::TYPE_OBJECT === $subject->type) {
            $matches = VmReflection::isSameClass($subject, $parentName);
        } elseif (Variable::TYPE_STRING === $subject->type) {
            $matches = strtolower($subject->toString()) === strtolower($parentName);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($matches);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args) && 3 !== \count($args)) {
            throw new \LogicException('is_subclass_of() requires two or three arguments');
        }
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'is_subclass_of() subject');
        }
        $parentName = ReflectionBuiltinHelper::requireCompileTimeClassName(
            $context,
            $args[1],
            'is_subclass_of() parent class'
        );
        if (JITVariable::TYPE_OBJECT === $args[0]->type) {
            return $context->helper->loadValue(
                ReflectionBuiltinHelper::emitInstanceOf($context, $args[0], $parentName)
            );
        }
        $childName = ReflectionBuiltinHelper::requireCompileTimeClassName(
            $context,
            $args[0],
            'is_subclass_of() child class'
        );
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(
            strtolower($childName) === strtolower($parentName) ? 1 : 0,
            false
        );
    }
}
