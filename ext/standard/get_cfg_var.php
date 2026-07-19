<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** get_cfg_var() — read PHP ini cfg values (ext/standard/ini.c, #6119). */
final class get_cfg_var extends Internal
{
    public function __construct()
    {
        parent::__construct('get_cfg_var');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError('get_cfg_var() expects exactly 1 argument, '.\count($frame->calledArgs).' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (InternalStrictArg::isCallerStrict($frame)) {
            $option = InternalStrictArg::requireString($frame, 0, 'get_cfg_var', 'option')->toString();
        } else {
            $resolved = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL === $resolved->type) {
                $option = '';
            } else {
                $option = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'get_cfg_var', 0, 'option');
            }
        }
        $result = VmIni::getCfgVar($option);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('get_cfg_var() requires exactly one argument');
        }
        if ($context->callerStrictTypes) {
            // TypeError without linking IniRuntime for compile-time null (#16526 / #20361 AOT).
            if (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant
                || (JITVariable::TYPE_STRING !== $args[0]->type
                    && JITVariable::TYPE_VALUE !== $args[0]->type
                    && JITVariable::TYPE_OBJECT !== $args[0]->type)) {
                JitStringBuiltinArg::lowerStrictOrCoercible(
                    $context,
                    $args[0],
                    'get_cfg_var',
                    0,
                    'option'
                );

                return $context->getTypeFromString('__value__*')->constNull();
            }
            $optionStr = JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $args[0],
                'get_cfg_var',
                0,
                'option'
            );
        } elseif (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant) {
            $optionStr = $context->builder->load($context->constantStringFromString(''));
        } else {
            $optionStr = JitStringBuiltinArg::lower($context, $args[0], 'get_cfg_var', 0, 'option');
        }

        return JitIni::getCfgVar($context, $optionStr);
    }
}
