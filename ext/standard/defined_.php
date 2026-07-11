<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\DefineRuntime;
use PHPCompiler\JIT\Builtin\GlobalIntrospectionNameRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** defined() — whether a user constant is registered (issue #204). */
final class defined_ extends Internal
{
    public function __construct()
    {
        parent::__construct('defined');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('defined() requires exactly one argument');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('defined() requires VM context');
        }
        $name = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'defined', 0, 'constant_name');
        $name = VmReflection::normalizeGlobalIntrospectionName($name);
        $defined = VmConstants::constantDefined($frame->vmContext, $name);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($defined);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== count($args)) {
            throw new \LogicException('defined() requires exactly one argument');
        }
        if (JITVariable::TYPE_VALUE === $args[0]->type || JITVariable::TYPE_OBJECT === $args[0]->type) {
            JitStringBuiltinArg::lower($context, $args[0], 'defined', 0, 'constant_name');
        }
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            $literal = VmReflection::normalizeGlobalIntrospectionName($literal);
            if (null !== $context->runtime->vmContext
                && VmConstants::constantDefined($context->runtime->vmContext, $literal)) {
                $i1 = $context->getTypeFromString('int1');

                return $i1->constInt(1, false);
            }
            $nameStr = $context->builder->load($context->constantStringFromString($literal));

            return DefineRuntime::emitDefined($context, $nameStr);
        }
        $nameStr = $context->helper->loadValue($args[0]);
        $nameStr = GlobalIntrospectionNameRuntime::normalizeString($context, $nameStr);

        return DefineRuntime::emitDefined($context, $nameStr);
    }
}
