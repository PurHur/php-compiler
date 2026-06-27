<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
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
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('method_exists() requires exactly two arguments');
        }
        $ctx = VmReflection::requireContext($frame);
        $method = VmReflection::stringArg($frame->calledArgs[1], 'method_exists() method name', 1);
        $exists = VmReflection::methodExists($ctx, $frame->calledArgs[0], $method);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($exists);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('method_exists() requires exactly two arguments');
        }
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
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'method_exists() class name');
        }
        if (JITVariable::TYPE_STRING === $args[1]->type || JITVariable::TYPE_VALUE === $args[1]->type) {
            $this->jitString($context, $args[1], 'method_exists() method name');
        }

        return JitMethodExists::invoke($context, $args[0], $args[1]);
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
        return match ($type) {
            JITVariable::TYPE_NATIVE_LONG => \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'int'),
            JITVariable::TYPE_NATIVE_DOUBLE => \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'float'),
            JITVariable::TYPE_NATIVE_BOOL => \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'bool'),
            JITVariable::TYPE_NULL => \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'null'),
            default => \sprintf(self::OBJECT_OR_CLASS_TYPE_ERROR, 'mixed'),
        };
    }
}
