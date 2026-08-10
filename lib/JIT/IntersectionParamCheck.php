<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectType;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPLLVM\Builder;

/**
 * Intersection type parameter checks at JIT/AOT call sites (#1357, #3077).
 */
final class IntersectionParamCheck
{
    /**
     * @param list<string> $interfaceLcs lowercase interface names
     */
    public static function enforce(
        Context $context,
        Variable $arg,
        array $interfaceLcs,
        string $kind = 'Argument'
    ): void {
        if ([] === $interfaceLcs) {
            return;
        }
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $objectType = $context->type->object;
        assert($objectType instanceof ObjectType);
        $expected = self::formatIntersectionType($interfaceLcs);
        if (Variable::TYPE_VALUE === $arg->type) {
            self::enforceValueBoxIsObjectOrFail($context, $arg, $kind, $expected);
        }
        $scalarGiven = self::scalarGivenLabel($arg);
        if (null !== $scalarGiven) {
            self::raiseTypeErrorAndAbort(
                $context,
                sprintf('%s must be of type %s, %s given', $kind, $expected, $scalarGiven)
            );

            return;
        }
        foreach ($interfaceLcs as $memberLc) {
            $ok = self::emitMemberSatisfies($context, $objectType, $arg, $memberLc);
            self::branchOnBoolOrRaise($context, $ok, $kind, $expected, $arg, $objectType);
        }
    }

    /**
     * @param list<string> $interfaceLcs
     */
    private static function formatIntersectionType(array $interfaceLcs): string
    {
        return implode('&', $interfaceLcs);
    }

    private static function raiseTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function emitMemberSatisfies(
        Context $context,
        ObjectType $objectType,
        Variable $arg,
        string $memberLc
    ): Variable {
        $memberLc = strtolower(ltrim($memberLc, '\\'));
        if ($objectType->isInterfaceClassLc($memberLc)) {
            return self::emitImplements($context, $objectType, $arg, $memberLc);
        }

        return $objectType->emitInstanceOf($arg, $memberLc);
    }

    private static function emitImplements(
        Context $context,
        ObjectType $objectType,
        Variable $arg,
        string $ifaceLc
    ): Variable {
        $ifaceLc = strtolower(ltrim($ifaceLc, '\\'));
        $obj = self::objectPointer($context, $arg);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );

        return self::emitClassIdImplements($context, $objectType, $classId, $ifaceLc);
    }

    private static function emitClassIdImplements(
        Context $context,
        ObjectType $objectType,
        \PHPLLVM\Value $classId,
        string $ifaceLc
    ): Variable {
        $i1 = $context->getTypeFromString('int1');
        $acc = $i1->constInt(0, false);
        foreach ($objectType->allClassNamesById() as $id => $name) {
            $classLc = strtolower(ltrim($name, '\\'));
            $ifaces = $objectType->allInterfacesForClassLc($classLc);
            $matches = in_array($ifaceLc, $ifaces, true)
                || ($objectType->isInterfaceClassLc($classLc) && $classLc === $ifaceLc)
                || ('stringable' === $ifaceLc && $objectType->classHasImplicitStringableLc($classLc));
            if (!$matches) {
                continue;
            }
            $expected = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $acc = $context->builder->or($acc, $isId);
        }

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $acc
        );
    }

    private static function branchOnBoolOrRaise(
        Context $context,
        Variable $ok,
        string $kind,
        string $expected,
        Variable $arg,
        ObjectType $objectType
    ): void {
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        $pass = $fn->appendBasicBlock('iface_ok');
        $fail = $fn->appendBasicBlock('iface_type_fail');
        $resume = $fn->appendBasicBlock('iface_resume');
        $bool = $context->helper->loadValue($ok);
        $context->builder->branchIf($bool, $pass, $fail);
        $context->builder->positionAtEnd($fail);
        $scalarGiven = self::scalarGivenLabel($arg);
        if (null !== $scalarGiven) {
            self::raiseTypeErrorAndAbort(
                $context,
                sprintf('%s must be of type %s, %s given', $kind, $expected, $scalarGiven)
            );
        } else {
            self::emitObjectFailureMessage($context, $objectType, $arg, $kind, $expected);
        }
        $context->builder->positionAtEnd($pass);
        $context->builder->branch($resume);
        $context->builder->positionAtEnd($resume);
    }

    private static function enforceValueBoxIsObjectOrFail(
        Context $context,
        Variable $arg,
        string $kind,
        string $expected
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $objectTy = $i8->constInt(\PHPCompiler\VM\Variable::TYPE_OBJECT, false);
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        $ok = $fn->appendBasicBlock('iface_value_is_object');
        $fail = $fn->appendBasicBlock('iface_value_not_object');
        $resume = $fn->appendBasicBlock('iface_value_checked');
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $context->builder->branchIf($isObject, $ok, $fail);
        $labels = [
            ['int', \PHPCompiler\VM\Variable::TYPE_INTEGER],
            ['float', \PHPCompiler\VM\Variable::TYPE_FLOAT],
            ['bool', \PHPCompiler\VM\Variable::TYPE_BOOLEAN],
            ['string', \PHPCompiler\VM\Variable::TYPE_STRING],
            ['null', \PHPCompiler\VM\Variable::TYPE_NULL],
            ['array', \PHPCompiler\VM\Variable::TYPE_ARRAY],
        ];
        $check = $fail;
        foreach ($labels as [$label, $ty]) {
            $match = $fn->appendBasicBlock('iface_value_ty_'.$label);
            $next = $fn->appendBasicBlock('iface_value_try_'.$label);
            $context->builder->positionAtEnd($check);
            $expectedTy = $i8->constInt($ty, false);
            $isTy = $context->builder->icmp(Builder::INT_EQ, $typeByte, $expectedTy);
            $context->builder->branchIf($isTy, $match, $next);
            $context->builder->positionAtEnd($match);
            self::raiseTypeErrorAndAbort(
                $context,
                sprintf('%s must be of type %s, %s given', $kind, $expected, $label)
            );
            $check = $next;
        }
        $context->builder->positionAtEnd($check);
        self::raiseTypeErrorAndAbort(
            $context,
            sprintf('%s must be of type %s, mixed given', $kind, $expected)
        );
        $context->builder->positionAtEnd($ok);
        $context->builder->branch($resume);
        $context->builder->positionAtEnd($resume);
    }

    private static function scalarGivenLabel(Variable $arg): ?string
    {
        return match ($arg->type) {
            Variable::TYPE_NATIVE_LONG => 'int',
            Variable::TYPE_NATIVE_DOUBLE => 'float',
            Variable::TYPE_NATIVE_BOOL => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_HASHTABLE => 'array',
            default => null,
        };
    }

    private static function emitObjectFailureMessage(
        Context $context,
        ObjectType $objectType,
        Variable $arg,
        string $kind,
        string $expected
    ): void {
        $obj = self::objectPointer($context, $arg);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        $entry = $context->builder->getInsertBlock();
        $defaultBlock = $fn->appendBasicBlock('iface_fail_default');
        $checkBlock = $entry;
        foreach ($objectType->allClassNamesById() as $id => $name) {
            // Preserve case; strip @anonymous\0file:line$id like zend %s (#29569 / #26031).
            $given = \PHPCompiler\MethodVisibility::formatAnonymousScopeForMessage(
                ltrim($name, '\\')
            );
            $message = sprintf('%s must be of type %s, %s given', $kind, $expected, $given);
            $matchBlock = $fn->appendBasicBlock('iface_fail_msg_'.$id);
            $nextBlock = $fn->appendBasicBlock('iface_fail_try_'.$id);
            $context->builder->positionAtEnd($checkBlock);
            $expectedId = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expectedId);
            $context->builder->branchIf($isId, $matchBlock, $nextBlock);
            $context->builder->positionAtEnd($matchBlock);
            self::raiseTypeErrorAndAbort($context, $message);
            $checkBlock = $nextBlock;
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($defaultBlock);
        $context->builder->positionAtEnd($defaultBlock);
        self::raiseTypeErrorAndAbort(
            $context,
            sprintf('%s must be of type %s, object given', $kind, $expected)
        );
    }

    private static function objectPointer(Context $context, Variable $arg): \PHPLLVM\Value
    {
        if (Variable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);

            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $valuePtr
            );
        }

        return $context->getTypeFromString('__object__*')->constNull();
    }
}
