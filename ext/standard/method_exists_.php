<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** method_exists() — whether a class defines a method (issue #1215). */
final class method_exists_ extends Internal
{
    private const OBJECT_OR_CLASS_TYPE_ERROR =
        'method_exists(): Argument #1 ($object_or_class) must be of type object|string, %s given';

    public function __construct()
    {
        parent::__construct('method_exists');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'method_exists() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $ctx = VmReflection::requireContext($frame);
        // Z_PARAM_STR — soft-null DEP+coerce on 8.4 (#21281, zend_builtin_functions.c).
        $method = VmString::trimFamilyStringArgForFrame($frame, 1, 'method_exists', 1, 'method');
        $exists = VmReflection::methodExists($ctx, $frame->calledArgs[0], $method);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($exists);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'method_exists() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR method — soft-null DEP+coerce on 8.4 (#21281); empty method never exists.
        if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
            self::jitMethodNameArg($context, $args[1]);
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        self::jitMethodNameArg($context, $args[1]);
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
            self::emitJitTypeErrorAndAbort(
                $context,
                \sprintf(
                    self::OBJECT_OR_CLASS_TYPE_ERROR,
                    JitOperandTypeLabel::givenLabel($context, $args[0])
                )
            );
            $i1 = $context->getTypeFromString('int1');

            return $i1->constInt(0, false);
        }
        // Arg #1 is object|string — do not jitString TYPE_VALUE (locals are value boxes that
        // may hold objects). Runtime dispatch is in JitMethodExists::invokeFromValueBox (#19616).
        if (JITVariable::TYPE_STRING === $args[0]->type) {
            $this->jitString($context, $args[0], 'method_exists() class name');
        }

        return JitMethodExists::invoke($context, $args[0], $args[1]);
    }

    private static function jitMethodNameArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'method_exists',
                1,
                'method'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'method_exists',
            1,
            'method'
        );
    }

    private static function emitJitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }
}
