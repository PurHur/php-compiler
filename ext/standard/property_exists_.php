<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** property_exists() — whether a class or object has a property (issue #1372). */
final class property_exists_ extends Internal
{
    private const OBJECT_OR_CLASS_TYPE_ERROR =
        'property_exists(): Argument #1 ($object_or_class) must be of type object|string, %s given';

    public function __construct()
    {
        parent::__construct('property_exists');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'property_exists() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $ctx = VmReflection::requireContext($frame);
        self::requireValidObjectOrClass($frame->calledArgs[0]->resolveIndirect());
        // Z_PARAM_STR — soft-null DEP+coerce on 8.4 (#21281, zend_builtin_functions.c).
        $property = VmString::trimFamilyStringArgForFrame($frame, 1, 'property_exists', 1, 'property');
        $exists = VmReflection::propertyExists($ctx, $frame->calledArgs[0], $property, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($exists);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'property_exists() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR property — soft-null DEP+coerce on 8.4 (#21281); empty property never exists.
        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            self::jitPropertyNameArg($context, $args[1]);
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        self::jitPropertyNameArg($context, $args[1]);
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            self::emitJitTypeErrorAndAbort($context, \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'null'));
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        if (!\in_array($args[0]->type, [
            JITVariable::TYPE_OBJECT,
            JITVariable::TYPE_STRING,
            JITVariable::TYPE_VALUE,
        ], true)) {
            self::emitJitTypeErrorAndAbort($context, self::jitTypeErrorMessage($args[0]->type));
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }

        return JitPropertyExists::invoke($context, $args[0], $args[1]);
    }

    private static function jitPropertyNameArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'property_exists',
                1,
                'property'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'property_exists',
            1,
            'property'
        );
    }

    private static function requireValidObjectOrClass(Variable $objectOrClass): void
    {
        if (Variable::TYPE_STRING === $objectOrClass->type
            || Variable::TYPE_OBJECT === $objectOrClass->type
            || Variable::TYPE_ENUM_CASE === $objectOrClass->type) {
            return;
        }
        throw new \TypeError(\sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, EnumCaseSupport::typeNameForTypeErrorActual($objectOrClass)));
    }

    private static function emitJitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function jitTypeErrorMessage(int $type): string
    {
        switch ($type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'int');
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'float');
            case JITVariable::TYPE_NATIVE_BOOL:
                return \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'bool');
            case JITVariable::TYPE_NULL:
                return \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'null');
            default:
                return \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'mixed');
        }
    }

}
