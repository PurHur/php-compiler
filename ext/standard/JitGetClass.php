<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_class() — class name or TypeError (#1217, #5456, #4092). */
final class JitGetClass
{
    private const TYPE_ERROR = 'get_class(): Argument #1 ($object) must be of type object, %s given';

    private const NO_THIS_ERROR = 'get_class() without arguments must be called from within a class';

    public static function invokeNoArg(Context $context): Value
    {
        if (CompilerVersion::supportsGetClassParentClassParameterlessDeprecation()) {
            VmEngineBuiltinDeprecation::emitJitCallingWithoutArguments($context, 'get_class');
        }
        $block = $context->jitEnclosingBlock;
        if (!$block instanceof Block || null === $block->func || null === $block->func->class) {
            self::emitNoThisErrorAndAbort($context);

            return self::emptyStringBox($context);
        }

        return self::boxString(
            $context,
            $context->builder->load($context->constantStringFromString($block->func->class->value))
        );
    }

    public static function invoke(
        Context $context,
        JITVariable $arg,
        ?Value $allowString = null,
        bool $allowStringKnownFalse = true
    ): Value {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return self::invokeStringOperand($context, $arg, $allowString, $allowStringKnownFalse);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return ReflectionBuiltinHelper::getClassName($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::boxed($context, $arg, $allowString, $allowStringKnownFalse);
        }

        self::emitTypeErrorAndAbort(
            $context,
            \sprintf(self::TYPE_ERROR, JitOperandTypeLabel::givenLabel($context, $arg))
        );

        return $context->builder->load($context->constantStringFromString(''));
    }

    private static function invokeStringOperand(
        Context $context,
        JITVariable $arg,
        ?Value $allowString,
        bool $allowStringKnownFalse
    ): Value {
        if ($allowStringKnownFalse) {
            self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'string'));

            return self::emptyStringBox($context);
        }
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null === $literal) {
            throw new \LogicException(
                'get_class() class name string must be a compile-time literal in this compiler build'
            );
        }
        if (null !== $allowString) {
            $trueBlock = BasicBlockHelper::append($context, 'get_class_allow_string_true');
            $falseBlock = BasicBlockHelper::append($context, 'get_class_allow_string_false');
            $context->builder->branchIf($allowString, $trueBlock, $falseBlock);

            $context->builder->positionAtEnd($falseBlock);
            self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'string'));

            $context->builder->positionAtEnd($trueBlock);
            $resolved = self::resolveAllowStringLiteral($context, $literal);

            return self::boxString(
                $context,
                $context->builder->load($context->constantStringFromString($resolved))
            );
        }

        return self::boxString(
            $context,
            $context->builder->load($context->constantStringFromString(self::resolveAllowStringLiteral($context, $literal)))
        );
    }

    private static function resolveAllowStringLiteral(Context $context, string $className): string
    {
        $vm = $context->runtime->vmContext;
        if (null === $vm) {
            return $className;
        }
        try {
            return VmReflection::resolveAllowStringClassName($vm, $className, 'get_class');
        } catch (\ValueError $e) {
            self::emitTypeErrorAndAbort($context, $e->getMessage());

            return $className;
        }
    }

    private static function boxed(
        Context $context,
        JITVariable $arg,
        ?Value $allowString,
        bool $allowStringKnownFalse
    ): Value {
        $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($loaded, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        // Mask IS_REFCOUNTED — AOT stores JIT tags (TYPE_OBJECT|0x80). Unmasked
        // compare vs VM TYPE_OBJECT=5 missed objects and aborted (#26854 / #21921).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NULL & 0x7f, false)
        );
        $okBlock = BasicBlockHelper::append($context, 'get_class_ok');
        $checkStringBlock = BasicBlockHelper::append($context, 'get_class_check_string');
        $stringErrBlock = BasicBlockHelper::append($context, 'get_class_string_err');
        $checkNullBlock = BasicBlockHelper::append($context, 'get_class_check_null');
        $nullErrBlock = BasicBlockHelper::append($context, 'get_class_null_err');
        $mixedErrBlock = BasicBlockHelper::append($context, 'get_class_mixed_err');
        $context->builder->branchIf($isObject, $okBlock, $checkStringBlock);

        $context->builder->positionAtEnd($checkStringBlock);
        $context->builder->branchIf($isString, $stringErrBlock, $checkNullBlock);

        $context->builder->positionAtEnd($stringErrBlock);
        if ($allowStringKnownFalse) {
            self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'string'));
        } else {
            throw new \LogicException(
                'get_class() runtime string operand with allow_string requires a compile-time literal in this compiler build'
            );
        }

        // checkNull was missing a terminator; the isNull branch was incorrectly
        // appended to stringErr after abort, leaving checkNull unterminated and
        // miscompiling the object path under thin AOT (#26854).
        $context->builder->positionAtEnd($checkNullBlock);
        $context->builder->branchIf($isNull, $nullErrBlock, $mixedErrBlock);

        $context->builder->positionAtEnd($nullErrBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'null'));

        $context->builder->positionAtEnd($mixedErrBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'mixed'));

        $context->builder->positionAtEnd($okBlock);
        self::emitResourceOperandGuard($context, $arg);

        return ReflectionBuiltinHelper::getClassName($context, $arg);
    }

    private static function emitResourceOperandGuard(Context $context, JITVariable $arg): void
    {
        $resourceClassId = self::resourceClassId($context);
        if (null === $resourceClassId) {
            return;
        }
        $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $loaded
        );
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $isResource = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $context->getTypeFromString('int64')->constInt($resourceClassId, false)
        );
        $continueBlock = BasicBlockHelper::append($context, 'get_class_not_resource');
        $resourceErrBlock = BasicBlockHelper::append($context, 'get_class_resource_err');
        $context->builder->branchIf($isResource, $resourceErrBlock, $continueBlock);
        $context->builder->positionAtEnd($resourceErrBlock);
        self::emitTypeErrorAndAbort($context, \sprintf(self::TYPE_ERROR, 'resource'));
        $context->builder->positionAtEnd($continueBlock);
    }

    private static function resourceClassId(Context $context): ?int
    {
        try {
            return $context->type->object->lookup('Resource');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
        // Thin AOT miscompiles the parent function when abort blocks lack a
        // terminator (unterminated siblings poison the object ok-path, #26854).
        if (null === $context->builder->getInsertBlock()?->getTerminator()) {
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }
    }

    private static function emitNoThisErrorAndAbort(Context $context): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, self::NO_THIS_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function boxString(Context $context, Value $nativeStr): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $nativeStr
        );

        return JitValueBox::pointer($context, $slot);
    }

    private static function emptyStringBox(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $context->builder->load($context->constantStringFromString(''))
        );

        return JitValueBox::pointer($context, $slot);
    }
}
