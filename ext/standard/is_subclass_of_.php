<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringClassExists;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * is_subclass_of() — extends-chain matching (php-src Zend/zend_builtin_functions.c, issue #3478).
 *
 * Z_PARAM_STR $class: declare(strict_types=1) → TypeError on null; else soft-null DEP+coerce (#29817).
 */
final class is_subclass_of_ extends Internal
{
    public function __construct()
    {
        parent::__construct('is_subclass_of');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'is_subclass_of() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'is_subclass_of() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        $ctx = VmReflection::requireContext($frame);
        // php-src zend_builtin_functions.stub.php — string $class (Z_PARAM_STR, #29817).
        $parentName = VmString::trimFamilyStringArgForFrame($frame, 1, 'is_subclass_of', 1, 'class');
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
                // zend_lookup_class — autoload string subject (#26406).
                $matches = VmReflection::isSubclassOf($ctx, $subject->toString(), $parentName);
            }
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($matches);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'is_subclass_of() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'is_subclass_of() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR $class — strict TypeError / soft-null DEP (#29817).
        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            self::jitClassArg($context, $args[1]);
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        $allowString = $context->constantFromBool(true);
        $allowStringKnownFalse = false;
        if (3 === \count($args)) {
            $allowString = JitBoolArg::lower($context, $args[2], 'is_subclass_of() allow_string');
            $allowStringKnownFalse = self::jitAllowStringKnownFalse($context, $args[2]);
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
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        if ($allowStringKnownFalse) {
            return $falseVal;
        }
        // Runtime helper — autoloads like zend_lookup_class (#26406).
        $childStr = JitStringArg::lower($context, $args[0], 'is_subclass_of() child class');
        $parentStr = $context->builder->load($context->constantStringFromString($parentName));
        $match = StringClassExists::invokeIsSubclassOfString($context, $childStr, $parentStr);

        return $context->builder->select(
            $allowString,
            $match,
            $falseVal
        );
    }

    private static function jitAllowStringKnownFalse(Context $context, JITVariable $arg): bool
    {
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            return 0 === (int) $context->llvm->lib->LLVMConstIntGetZExtValue($arg->value->value);
        }

        return false;
    }

    /** Z_PARAM_STR $class — caller strict_types vs soft-null (#29817). */
    private static function jitClassArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'is_subclass_of',
                1,
                'class'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'is_subclass_of',
            1,
            'class'
        );
    }
}
