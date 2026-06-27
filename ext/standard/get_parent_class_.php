<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * get_parent_class() — parent class from extends chain (issue #3483).
 *
 * php-src: ext/standard/class.c — PHP_FUNCTION(get_parent_class)
 */
final class get_parent_class_ extends Internal
{
    private const OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR =
        'get_parent_class(): Argument #1 ($object_or_class) must be an object or a valid class name, %s given';

    public function __construct()
    {
        parent::__construct('get_parent_class');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'get_parent_class() expects at most 1 argument, '.$argc.' given'
            );
        }
        if ($argc < 1) {
            throw new \LogicException('get_parent_class() requires one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $arg->type) {
            $frame->returnVar->bool(false);

            return;
        }
        $entry = null;
        if (Variable::TYPE_OBJECT === $arg->type) {
            if (EnumCaseSupport::isEnumCase($arg->toObject())) {
                $frame->returnVar->bool(false);

                return;
            }
            $entry = $arg->toObject()->class;
        } elseif (Variable::TYPE_STRING === $arg->type) {
            VmReflection::stringArg($arg, 'get_parent_class() class name', 0);
            $entry = VmReflection::resolveClassEntry($ctx, $arg->toString());
        } else {
            VmClassHas::requireObjectOrValidClassName($arg, 'get_parent_class');
        }
        if (null === $entry || $entry->isInterface || $entry->isTrait || $entry->isEnum) {
            $frame->returnVar->bool(false);

            return;
        }
        $parentName = VmReflection::parentClassName($entry, $ctx);
        if (null === $parentName) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($parentName);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'get_parent_class() expects at most 1 argument, '.$argc.' given'
            );

            return $context->getTypeFromString('int32')->constInt(0, false);
        }
        if ($argc < 1) {
            throw new \LogicException('get_parent_class() requires one or two arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'get_parent_class() class name');
        }
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            self::emitJitTypeErrorAndAbort(
                $context,
                \sprintf(self::OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR, 'null')
            );

            return $context->getTypeFromString('int32')->constInt(0, false);
        }
        if (!\in_array($args[0]->type, [
            JITVariable::TYPE_OBJECT,
            JITVariable::TYPE_STRING,
            JITVariable::TYPE_VALUE,
        ], true)) {
            self::emitJitTypeErrorAndAbort($context, self::jitTypeErrorMessage($args[0]->type));

            return $context->getTypeFromString('int32')->constInt(0, false);
        }

        return JitGetParentClass::invoke($context, $args[0]);
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
            JITVariable::TYPE_NATIVE_LONG => \sprintf(self::OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR, 'int'),
            JITVariable::TYPE_NATIVE_DOUBLE => \sprintf(self::OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR, 'float'),
            JITVariable::TYPE_NATIVE_BOOL => \sprintf(self::OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR, 'bool'),
            JITVariable::TYPE_NULL => \sprintf(self::OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR, 'null'),
            default => \sprintf(self::OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR, 'mixed'),
        };
    }
}
