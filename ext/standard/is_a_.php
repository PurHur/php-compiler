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
 * is_a() — extends-chain instance check (php-src Zend/zend_builtin_functions.c, issue #3478).
 *
 * Z_PARAM_STR $class: declare(strict_types=1) → TypeError on null; else soft-null DEP+coerce (#29817).
 */
final class is_a_ extends Internal
{
    public function __construct()
    {
        parent::__construct('is_a');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'is_a() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'is_a() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        $ctx = VmReflection::requireContext($frame);
        // php-src zend_builtin_functions.stub.php — string $class (Z_PARAM_STR, #29817).
        $className = VmString::trimFamilyStringArgForFrame($frame, 1, 'is_a', 1, 'class');
        // Z_PARAM_BOOL $allow_string — strict_types TypeError on null; else null→false + E_DEPRECATED (#31339).
        $allowString = false;
        if (3 === \count($frame->calledArgs)) {
            $allowString = VmMath::parseBoolBuiltinArgForFrame($frame, 2, 'is_a', 3, 'allow_string');
        }
        $subject = $frame->calledArgs[0]->resolveIndirect();
        $matches = false;
        if (Variable::TYPE_OBJECT === $subject->type || Variable::TYPE_ENUM_CASE === $subject->type) {
            $matches = VmReflection::isInstanceOfObject($ctx, $subject, $className);
        } elseif ($allowString && Variable::TYPE_STRING === $subject->type) {
            // zend_lookup_class — autoload string subject (#26406).
            $matches = VmReflection::isAString($ctx, $subject->toString(), $className);
        }
        // null/scalar/array subjects — false without TypeError (php-src class.c, #10873).
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($matches);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'is_a() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'is_a() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR $class — strict TypeError / soft-null DEP (#29817).
        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            self::jitClassArg($context, $args[1]);
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        $allowString = $context->constantFromBool(false);
        $allowStringKnownFalse = true;
        if (3 === \count($args)) {
            // Z_PARAM_BOOL — match VM parseBoolBuiltinArgForFrame (#31339).
            $allowString = JitBoolArg::lowerCoerceZParamBool(
                $context,
                $args[2],
                'is_a',
                'allow_string',
                3
            );
            $allowStringKnownFalse = self::jitAllowStringKnownFalse($context, $args[2]);
        }
        if (!\in_array($args[0]->type, [
            JITVariable::TYPE_OBJECT,
            JITVariable::TYPE_STRING,
            JITVariable::TYPE_VALUE,
        ], true)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $className = ReflectionBuiltinHelper::requireCompileTimeClassName(
            $context,
            $args[1],
            'is_a() class name'
        );
        if (JITVariable::TYPE_STRING === $args[0]->type) {
            $this->jitString($context, $args[0], 'is_a() subject');
            $i1 = $context->getTypeFromString('int1');
            $falseVal = $i1->constInt(0, false);
            if ($allowStringKnownFalse) {
                return $falseVal;
            }
            // Runtime helper — autoloads like zend_lookup_class (#26406). Compile-time
            // fold would skip registered autoloaders for not-yet-loaded class strings.
            $childStr = JitStringArg::lower($context, $args[0], 'is_a() subject');
            $classStr = $context->builder->load($context->constantStringFromString($className));
            $match = StringClassExists::invokeIsAString($context, $childStr, $classStr);

            return $context->builder->select(
                $allowString,
                $match,
                $falseVal
            );
        }

        return $context->helper->loadValue(
            ReflectionBuiltinHelper::emitInstanceOf($context, $args[0], $className)
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
            return JitStringBuiltinArg::lowerStrictOrCoercible($context, $arg, 'is_a', 1, 'class');
        }

        return JitStringBuiltinArg::lowerTrimFamilyString($context, $arg, 'is_a', 1, 'class');
    }

}
