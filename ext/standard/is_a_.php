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
 * is_a() — extends-chain instance check (php-src ext/standard/class.c, issue #3478).
 */
final class is_a_ extends Internal
{
    public function __construct()
    {
        parent::__construct('is_a');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs) && 3 !== \count($frame->calledArgs)) {
            throw new \LogicException('is_a() requires two or three arguments');
        }
        $ctx = VmReflection::requireContext($frame);
        $className = VmReflection::stringArg($frame->calledArgs[1], 'is_a() class name');
        $allowString = false;
        if (3 === \count($frame->calledArgs)) {
            $allowString = $frame->calledArgs[2]->resolveIndirect()->toBool();
        }
        $subject = $frame->calledArgs[0]->resolveIndirect();
        $matches = false;
        if (Variable::TYPE_OBJECT === $subject->type) {
            $matches = VmReflection::isInstanceOfObject($ctx, $subject, $className);
        } elseif ($allowString && Variable::TYPE_STRING === $subject->type) {
            $child = VmReflection::resolveClassEntry($ctx, $subject->toString());
            $matches = null !== $child
                && VmReflection::isInstanceOf($ctx, $child, $className);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($matches);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args) && 3 !== \count($args)) {
            throw new \LogicException('is_a() requires two or three arguments');
        }
        $allowString = $context->constantFromBool(false);
        if (3 === \count($args)) {
            $allowString = JitBoolArg::lower($context, $args[2], 'is_a() allow_string');
        }
        $className = ReflectionBuiltinHelper::requireCompileTimeClassName(
            $context,
            $args[1],
            'is_a() class name'
        );
        if (JITVariable::TYPE_STRING === $args[0]->type) {
            $this->jitString($context, $args[0], 'is_a() subject');
            $subjectName = ReflectionBuiltinHelper::requireCompileTimeClassName(
                $context,
                $args[0],
                'is_a() subject class name'
            );
            $i1 = $context->getTypeFromString('int1');
            $match = ReflectionBuiltinHelper::classIsInstanceOfLiteral(
                $context,
                $subjectName,
                $className
            );

            return $context->builder->select(
                $allowString,
                $match,
                $i1->constInt(0, false)
            );
        }
        return $context->helper->loadValue(
            ReflectionBuiltinHelper::emitInstanceOf($context, $args[0], $className)
        );
    }
}
