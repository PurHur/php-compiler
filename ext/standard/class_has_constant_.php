<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * class_has_constant() — historical phantom free-function wrapping ReflectionClass::hasConstant (#9989).
 *
 * Not registered on php-src-strict profiles (#28413): php-src has only ReflectionClass::hasConstant.
 * Kept for Module gating / JIT helper wiring only.
 */
final class class_has_constant_ extends Internal
{
    private const FUNCTION = 'class_has_constant';

    private const OBJECT_OR_CLASS_TYPE_ERROR =
        'class_has_constant(): Argument #1 ($object_or_class) must be of type object|string, %s given';

    public function __construct()
    {
        parent::__construct(self::FUNCTION);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException(self::FUNCTION.'() requires two to four arguments');
        }
        $ctx = VmReflection::requireContext($frame);
        VmClassHas::requireObjectOrClass($frame->calledArgs[0], self::FUNCTION, 'object_or_class');
        $constant = VmString::coerceStringBuiltinArg($frame->calledArgs[1], self::FUNCTION, 1, 'constant');
        $autoload = VmClassHas::parseAutoload($frame->calledArgs, self::FUNCTION);
        $allowString = VmClassHas::parseAllowString($frame->calledArgs, self::FUNCTION);
        $entry = VmClassHas::resolveClassEntryForHas($ctx, $frame->calledArgs[0], $autoload, $allowString);
        $exists = null !== $entry && VmReflection::classHasConstantForReflection($entry, $ctx, $constant);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($exists);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException(self::FUNCTION.'() requires two to four arguments');
        }
        self::emitJitOperandChecks($context, $args[0]);
        self::emitJitStringArgChecks($context, $args[1], 1, 'constant');
        $classLiteral = JitStringArg::compileTimeLiteral($args[0]);
        $constantLiteral = JitStringArg::compileTimeLiteral($args[1]);
        if (null !== $classLiteral && null !== $constantLiteral) {
            return ReflectionBuiltinHelper::classHasConstantLiteral($context, $classLiteral, $constantLiteral);
        }

        throw new \LogicException(
            self::FUNCTION.'() class and constant names must be string literals in JIT in this compiler build'
        );
    }

    private static function emitJitOperandChecks(Context $context, JITVariable $arg): void
    {
        if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
            return;
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitJitTypeErrorAndAbort($context, \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'object'));

            return;
        }
        JitStringBuiltinArg::lower($context, $arg, self::FUNCTION, 0, 'object_or_class');
    }

    private static function emitJitStringArgChecks(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): void {
        if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
            return;
        }
        JitStringBuiltinArg::lower($context, $arg, self::FUNCTION, $argIndex, $paramName);
    }

    private static function emitJitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }
}
