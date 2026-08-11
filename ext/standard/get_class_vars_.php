<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * get_class_vars() — default values for properties visible from the calling scope (#3159, #23531).
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(get_class_vars) / add_class_vars
 * Class operand is zend_parse_parameters "C" — null TypeError without Z_PARAM_STR DEP (#30060).
 */
final class get_class_vars_ extends Internal
{
    private const INVALID_CLASS_NAME_TYPE_ERROR =
        'get_class_vars(): Argument #1 ($class) must be a valid class name, %s given';

    public function __construct()
    {
        parent::__construct('get_class_vars');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'get_class_vars() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        // "C" — soft-null without Z_PARAM_STR deprecation (#30060).
        $className = VmString::coerceClassNameParamArg($frame->calledArgs[0], 'get_class_vars', 0, 'class');
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $entry = VmReflection::fetchClassEntryForGetClassVars($ctx, $className);
        $frame->returnVar->copyFrom(VmReflection::getClassVarsArray($entry, $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'get_class_vars() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        // Compile-time null / non-string scalar — zend "C" TypeError, no Z_PARAM_STR DEP (#30060).
        $invalidGiven = self::compileTimeInvalidClassNameGiven($args[0]);
        if (null !== $invalidGiven) {
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                \sprintf(self::INVALID_CLASS_NAME_TYPE_ERROR, $invalidGiven)
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            return JitGetClassVars::invoke($context, $args[0]);
        }
        // NestedJIT / VALUE: "C" null guard (thin AOT stubs helper, #27229) then PHP bridge.
        if (JITVariable::TYPE_VALUE === $args[0]->type) {
            self::emitRuntimeNullClassNameGuard($context, $args[0]);

            return JitGetClassVars::invokeFromValueBox($context, $args[0]);
        }
        JitStringBuiltinArg::lower($context, $args[0], 'get_class_vars', 0, 'class');
        throw new \LogicException(
            'get_class_vars() class must be a string literal in this compiler build'
        );
    }

    /**
     * Stringified "given" label for compile-time non-class "C" operands (zend convert_to_string).
     *
     * @return string|null null when the operand needs string/VALUE/object runtime handling
     */
    private static function compileTimeInvalidClassNameGiven(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return '';
        }
        if (null !== ($arg->compileTimeLong ?? null)
            && \in_array($arg->type, [
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::TYPE_NATIVE_BOOL,
                JITVariable::TYPE_VALUE,
            ], true)
        ) {
            if (JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
                return 0 !== $arg->compileTimeLong ? '1' : '';
            }

            return (string) $arg->compileTimeLong;
        }
        if (null !== ($arg->compileTimeFloat ?? null)
            && \in_array($arg->type, [JITVariable::TYPE_NATIVE_DOUBLE, JITVariable::TYPE_VALUE], true)
        ) {
            return (string) $arg->compileTimeFloat;
        }

        return null;
    }

    /** Runtime null VALUE → ",  given" without Z_PARAM_STR DEP (#30060 / #27229). */
    private static function emitRuntimeNullClassNameGuard(Context $context, JITVariable $classArg): void
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $classArg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $nullErrBlock = BasicBlockHelper::append($context, 'gcv_value_null');
        $okBlock = BasicBlockHelper::append($context, 'gcv_value_ok');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeKind,
                $i8->constInt(VmVariable::TYPE_NULL, false)
            ),
            $nullErrBlock,
            $okBlock
        );
        $context->builder->positionAtEnd($nullErrBlock);
        ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            \sprintf(self::INVALID_CLASS_NAME_TYPE_ERROR, '')
        );
        $context->builder->positionAtEnd($okBlock);
    }
}
