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
        $name = self::vmConstantNameArg($frame);
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
        $i1 = $context->getTypeFromString('int1');
        // Z_PARAM_STR — soft-null DEP+coerce on 8.4 (#21281); empty name is never defined.
        $nameStr = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'defined',
            0,
            'constant_name'
        );
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            return $i1->constInt(0, false);
        }
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            $literal = VmReflection::normalizeGlobalIntrospectionName($literal);
            if (null !== $context->runtime->vmContext
                && VmConstants::constantDefined($context->runtime->vmContext, $literal)) {
                return $i1->constInt(1, false);
            }
            $nameStr = $context->builder->load($context->constantStringFromString($literal));

            return DefineRuntime::emitDefined($context, $nameStr);
        }
        $nameStr = GlobalIntrospectionNameRuntime::normalizeString($context, $nameStr);

        return DefineRuntime::emitDefined($context, $nameStr);
    }

    /** Z_PARAM_STR — soft-null DEP+coerce on 8.4 (#21281, ext/standard/basic_functions.c). */
    private static function vmConstantNameArg(Frame $frame): string
    {
        return VmString::trimFamilyStringArgForFrame(
            $frame,
            0,
            'defined',
            0,
            'constant_name'
        );
    }
}
