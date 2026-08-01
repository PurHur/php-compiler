<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** get_class() — class name of an object (issue #1217, #5456, #17395). */
final class get_class_ extends Internal
{
    private const TYPE_ERROR = 'get_class(): Argument #1 ($object) must be of type object, %s given';

    public function __construct()
    {
        parent::__construct('get_class');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        VmReflection::enforceGetClassMaxArgs($argc);
        if (0 === $argc) {
            if (CompilerVersion::supportsGetClassParentClassParameterlessDeprecation()) {
                VmEngineBuiltinDeprecation::emitCallingWithoutArguments($frame, 'get_class');
            }
            $definingClass = VmReflection::zeroArgGetClassName($frame);
            BuiltinExecute::writeReturn(
                $frame,
                static function (Variable $ret) use ($definingClass): void {
                    $ret->string($definingClass);
                }
            );

            return;
        }
        $allowString = false;
        if (2 === $argc) {
            $allowString = VmReflection::parseAllowStringArg($frame, 'get_class', 1);
        }
        $value = $frame->calledArgs[0]->resolveIndirect();
        BuiltinExecute::writeReturn(
            $frame,
            function (Variable $ret) use ($frame, $value, $allowString): void {
                if (Variable::TYPE_STRING === $value->type) {
                    if (!$allowString) {
                        throw new \TypeError(\sprintf(self::TYPE_ERROR, 'string'));
                    }
                    $ctx = VmReflection::requireContext($frame);
                    $className = VmReflection::resolveAllowStringClassName(
                        $ctx,
                        $value->toString(),
                        'get_class'
                    );
                    $ret->string($className);

                    return;
                }
                if (Variable::TYPE_ENUM_CASE === $value->type) {
                    $ret->string($value->toEnumCase()->enumClass->name);

                    return;
                }
                if (ResourceSupport::rejectsGetClassOperand($value)) {
                    throw new \TypeError(\sprintf(self::TYPE_ERROR, 'resource'));
                }
                if (Variable::TYPE_OBJECT !== $value->type) {
                    throw new \TypeError(\sprintf(self::TYPE_ERROR, self::vmTypeName($value->type)));
                }
                $ret->string($value->toObject()->class->name);
            }
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        $maxArgs = VmReflection::getClassMaxArgCount();
        if ($argc > $maxArgs) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                \sprintf('get_class() expects at most %d argument%s, %d given', $maxArgs, 1 === $maxArgs ? '' : 's', $argc)
            );

            return $context->getTypeFromString('int32')->constInt(0, false);
        }
        if (0 === $argc) {
            return JitGetClass::invokeNoArg($context);
        }
        $allowString = $context->constantFromBool(false);
        $allowStringKnownFalse = true;
        if (2 === $argc) {
            if (!CompilerVersion::supportsGetClassAllowString()) {
                TypeErrorRaise::ensureLinked($context);
                TypeErrorRaise::emitArgumentCountError(
                    $context,
                    'get_class() expects at most 1 argument, 2 given'
                );

                return $context->getTypeFromString('int32')->constInt(0, false);
            }
            $allowString = JitBoolArg::lower($context, $args[1], 'get_class() allow_string');
            $allowStringKnownFalse = self::jitAllowStringKnownFalse($context, $args[1]);
        }

        return JitGetClass::invoke($context, $args[0], $allowString, $allowStringKnownFalse);
    }

    private static function jitAllowStringKnownFalse(Context $context, JITVariable $arg): bool
    {
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            return 0 === (int) $context->llvm->lib->LLVMConstIntGetZExtValue($arg->value->value);
        }

        return false;
    }

    private static function vmTypeName(int $type): string
    {
        switch ($type) {
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return 'object';
            default:
                return 'mixed';
        }
    }
}
