<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;

/** JIT guards for preg_* $subject (array|string; #7154, ext/pcre/php_pcre.c). */
final class JitPregSubject
{
    public static function requireStringOrArray(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName = 'subject'
    ): void {
        if (self::isStringOrCoercibleNullSubject($arg)) {
            return;
        }
        if (JITVariable::TYPE_HASHTABLE === $arg->type
            || (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY))
        ) {
            return;
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            self::requireBoxedStringOrArray($context, $arg, $function, $argIndex, $paramName);

            return;
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitTypeErrorAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                self::compileTimeGivenLabel($context, $arg)
            );

            return;
        }
        self::emitTypeErrorAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            self::jitTypeLabel($arg->type)
        );
    }

    public static function isStringOrCoercibleNullSubject(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return true;
        }
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return true;
        }

        return false;
    }

    private static function requireBoxedStringOrArray(
        Context $context,
        JITVariable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $arrayTy = $i8->constInt(Variable::TYPE_ARRAY & 0x7f, false);
        $stringTy = $i8->constInt(Variable::TYPE_STRING & 0x7f, false);
        $enumCaseTy = $i8->constInt(Variable::TYPE_ENUM_CASE, false);
        $objectTy = $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false);

        $okBlock = BasicBlockHelper::append($context, 'preg_subj_ok');
        $checkBlock = BasicBlockHelper::append($context, 'preg_subj_check');
        $rejectBlock = BasicBlockHelper::append($context, 'preg_subj_reject');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeKind, $arrayTy);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeKind, $stringTy);
        $isAllowed = $context->builder->or($isArray, $isString);
        $context->builder->branchIf($isAllowed, $okBlock, $checkBlock);

        $context->builder->positionAtEnd($checkBlock);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeKind, $enumCaseTy);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeKind, $objectTy);
        $isReject = $context->builder->or($isEnumCase, $isObject);
        $context->builder->branchIf($isReject, $rejectBlock, $okBlock);

        $context->builder->positionAtEnd($rejectBlock);
        self::emitTypeErrorAndAbort(
            $context,
            $function,
            $argIndex,
            $paramName,
            self::compileTimeGivenLabel($context, $arg)
        );

        $context->builder->positionAtEnd($okBlock);
    }

    private static function compileTimeGivenLabel(Context $context, JITVariable $arg): string
    {
        $enumLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);
        if (null !== $enumLabel) {
            return $enumLabel;
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            $objMap = $context->structFieldMap['__object__'] ?? null;
            if (null !== $objMap && isset($objMap['class_id'])) {
                $classIdVal = $context->builder->load(
                    $context->builder->structGep($arg->value, $objMap['class_id'])
                );
                if (method_exists($classIdVal, 'isConstant') && $classIdVal->isConstant()) {
                    return $context->type->object->classNameForId((int) $classIdVal->getConstantValue());
                }
            }

            return 'object';
        }

        return 'mixed';
    }

    private static function emitTypeErrorAndAbort(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            \sprintf(
                '%s(): Argument #%d ($%s) must be of type array|string, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                $given
            )
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function jitTypeLabel(int $type): string
    {
        return match ($type) {
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_STRING => 'string',
            JITVariable::TYPE_HASHTABLE => 'array',
            JITVariable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}
