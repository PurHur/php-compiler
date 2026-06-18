<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * is_subclass_of() — extends-chain matching (php-src ext/standard/class.c, issue #3478).
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
        $parentName = VmReflection::stringArg($frame->calledArgs[1], 'is_subclass_of() class_name', 1);
        $allowString = true;
        if (3 === \count($frame->calledArgs)) {
            $allowString = $frame->calledArgs[2]->resolveIndirect()->toBool();
        }
        $subject = $frame->calledArgs[0]->resolveIndirect();
        $matches = false;
        if (Variable::TYPE_OBJECT === $subject->type || Variable::TYPE_ENUM_CASE === $subject->type) {
            $matches = VmReflection::isSubclassOfObject($ctx, $subject, $parentName);
        } elseif (Variable::TYPE_STRING === $subject->type) {
            if ($allowString) {
                $matches = VmReflection::isSubclassOf($ctx, $subject->toString(), $parentName);
            }
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
        $allowString = $context->constantFromBool(true);
        if (3 === \count($args)) {
            $allowString = JitBoolArg::lower($context, $args[2], 'is_subclass_of() allow_string');
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
                ReflectionBuiltinHelper::emitSubclassOf($context, $args[0], $parentName)
            );
        }
        $childName = ReflectionBuiltinHelper::requireCompileTimeClassName(
            $context,
            $args[0],
            'is_subclass_of() child class'
        );
        $match = ReflectionBuiltinHelper::classIsSubclassOfLiteral(
            $context,
            $childName,
            $parentName
        );

        return $context->builder->select(
            $allowString,
            $match,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
    }
}
