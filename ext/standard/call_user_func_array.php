<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * call_user_func_array() — invoke a callable with a packed parameter list (issue #3132).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(call_user_func_array)
 */
final class call_user_func_array extends Internal
{
    public function __construct()
    {
        parent::__construct('call_user_func_array');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('call_user_func_array() requires exactly two arguments');
        }
        $ctx = VmReflection::requireContext($frame);
        $params = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $params->type) {
            throw new \TypeError(
                'call_user_func_array(): Argument #2 ($args) must be of type array, '
                .self::typeLabel($params).' given'
            );
        }
        $result = VmCallable::invokeWithArgEntries(
            $ctx,
            $frame->calledArgs[0],
            VmCallable::arrayVariableToArgEntries($params)
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('call_user_func_array() requires exactly two arguments');
        }

        return JitCallUserFunc::invokeArray(
            $context,
            $args[0],
            $args[1],
            $context->jitCurrentBlock ?? $context->jitEnclosingBlock,
            $context->jitCallUserFuncArrayParamsOperand
        );
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_ENUM_CASE => 'object',
            default => 'mixed',
        };
    }
}
