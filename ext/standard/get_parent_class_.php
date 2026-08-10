<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * get_parent_class() — parent class from extends chain (issue #3483, #26369).
 *
 * php-src: Zend/zend_builtin_functions.stub.php — arity 0–1 (`object_or_class` optional, #23948)
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(get_parent_class)
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
        VmReflection::enforceGetParentClassMaxArgs($argc);
        if ($argc < 1) {
            if (CompilerVersion::supportsGetClassParentClassParameterlessDeprecation()) {
                VmEngineBuiltinDeprecation::emitCallingWithoutArguments($frame, 'get_parent_class');
            }
            if (null === $frame->returnVar) {
                return;
            }
            $parentName = VmReflection::zeroArgGetParentClassName($frame);
            if (null === $parentName) {
                $frame->returnVar->bool(false);

                return;
            }
            $frame->returnVar->string($parentName);

            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING === $arg->type) {
            $className = $arg->toString();
            $classLc = strtolower(VmReflection::normalizeGlobalIntrospectionName($className));
            if (!isset($ctx->classes[$classLc])) {
                $ctx->autoloadClass($className);
            }
            if (!isset($ctx->classes[$classLc])) {
                throw new \TypeError(\sprintf(
                    self::OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR,
                    'string'
                ));
            }
            $className = VmReflection::resolveAllowStringClassName(
                $ctx,
                $className,
                'get_parent_class',
                'object_or_class'
            );
            $entry = VmReflection::resolveClassEntry($ctx, $className);
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

            return;
        }
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
            return JitGetParentClass::invokeNoArg($context);
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
            self::emitJitTypeErrorAndAbort(
                $context,
                \sprintf(
                    self::OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR,
                    JitOperandTypeLabel::givenLabel($context, $args[0])
                )
            );

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
}
