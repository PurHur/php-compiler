<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\StringCaseCompare;
use PHPCompiler\VM\InstanceOfClassName;
use PHPCompiler\VM\InstanceOfJitHelper;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * instanceof lowering for literal and dynamic class operands (#4339, #10078).
 *
 * SSOT: {@see \PHPCompiler\VM\InstanceOfClassName}, {@see \PHPCompiler\VM\InstanceOfJitHelper}
 */
final class InstanceOfHelper
{
    public const ERROR_MESSAGE = InstanceOfClassName::ERROR_MESSAGE;

    private const HELPER_PATH = '/VM/InstanceOfJitHelper.php';

    private const VALUE_BOX_RHS_KIND_HELPER = 'PHPCompiler\\VM\\InstanceOfJitHelper::valueBoxRhsKind';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::VALUE_BOX_RHS_KIND_HELPER,
    ];

    public static function emit(Context $context, Variable $expr, Operand $classOp): Variable
    {
        if ($classOp instanceof Literal) {
            return $context->type->object->emitInstanceOf($expr, (string) $classOp->value);
        }

        return self::emitDynamic($context, $expr, $context->getVariableFromOp($classOp));
    }

    /**
     * instanceof against a runtime class id — late `static` LSB (#31746, zend_execute.c ZEND_INSTANCEOF).
     */
    public static function emitWithRuntimeClassId(Context $context, Variable $expr, Value $rhsClassId): Variable
    {
        return self::emitWithRhsClassId($context, $expr, $rhsClassId);
    }

    private static function emitDynamic(Context $context, Variable $expr, Variable $classVar): Variable
    {
        if (InstanceOfJitHelper::jitRhsTypeIsInvalidClass($classVar->type)) {
            self::emitInvalidClassRhsError($context);

            $i1 = $context->getTypeFromString('int1');

            return new Variable(
                $context,
                Variable::TYPE_NATIVE_BOOL,
                Variable::KIND_VALUE,
                $i1->constInt(0, false)
            );
        }
        if (Variable::TYPE_STRING === $classVar->type) {
            return self::emitWithClassNameString(
                $context,
                $expr,
                $context->helper->loadValue($classVar)
            );
        }
        if (Variable::TYPE_OBJECT === $classVar->type) {
            $objMap = $context->structFieldMap['__object__'];
            $obj = $context->helper->loadValue($classVar);
            $classId = $context->builder->load(
                $context->builder->structGep($obj, $objMap['class_id'])
            );

            return self::emitWithRhsClassId($context, $expr, $classId);
        }
        if (Variable::TYPE_VALUE === $classVar->type) {
            return self::emitWithBoxedClassVar($context, $expr, $classVar);
        }

        self::emitInvalidClassRhsError($context);

        $i1 = $context->getTypeFromString('int1');

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $i1->constInt(0, false)
        );
    }

    private static function emitWithBoxedClassVar(Context $context, Variable $expr, Variable $classVar): Variable
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $classVar);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $rhsKind = self::callValueBoxRhsKind($context, $typeByte);
        $i32 = $context->getTypeFromString('int32');

        $stringBlock = BasicBlockHelper::append($context, 'instanceof_rhs_str');
        $afterString = BasicBlockHelper::append($context, 'instanceof_rhs_after_str');
        $objectBlock = BasicBlockHelper::append($context, 'instanceof_rhs_obj');
        $invalidBlock = BasicBlockHelper::append($context, 'instanceof_rhs_invalid');
        $doneBlock = BasicBlockHelper::append($context, 'instanceof_rhs_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $rhsKind,
            $i32->constInt(InstanceOfJitHelper::RHS_KIND_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $stringResult = self::emitWithClassNameString($context, $expr, $strPtr);
        $stringBool = self::nativeBoolValue($context, $stringResult);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterString);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $rhsKind,
            $i32->constInt(InstanceOfJitHelper::RHS_KIND_OBJECT, false)
        );
        $context->builder->branchIf($isObject, $objectBlock, $invalidBlock);

        $context->builder->positionAtEnd($objectBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $objectResult = self::emitWithRhsClassId($context, $expr, $classId);
        $objectBool = self::nativeBoolValue($context, $objectResult);
        $objectEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($invalidBlock);
        self::emitInvalidClassRhsError($context);
        $invalidEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $i1 = $context->getTypeFromString('int1');
        $phi = $context->builder->phi($i1, 'instanceof_rhs_phi');
        $phi->addIncoming($stringBool, $stringEnd);
        $phi->addIncoming($objectBool, $objectEnd);
        $phi->addIncoming($i1->constInt(0, false), $invalidEnd);

        return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $phi);
    }

    private static function emitWithClassNameString(Context $context, Variable $expr, Value $classNameStr): Variable
    {
        // ensureStrcasecmpLinked clears the insert block when it emits the bridge (#32766).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringCaseCompare::ensureStrcasecmpLinked($context);
        StringCaseCompare::ensureStrncasecmpLinked($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

        // Length-checked strncasecmp — plain strcasecmp against constantFromString
        // produced false positives across the declared-class list under thin AOT (#32766).
        $objectType = $context->type->object;
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strncasecmp = $context->lookupFunction(StringCaseCompare::ABI_STRNCASECMP);
        $strMap = $context->structFieldMap['__string__'];
        $lenI64 = $context->builder->load(
            $context->builder->structGep($classNameStr, $strMap['length'])
        );
        $src = self::stringDataPtr($context, $classNameStr);
        $bufCap = 256;
        $buf = $context->builder->alloca($i8, $bufCap, 'instanceof_rhs_name');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $maxCopy = $i64->constInt($bufCap - 1, false);
        $lenClamped = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $lenI64, $maxCopy),
            $lenI64,
            $maxCopy
        );
        LibcExtern::ensureMemcpyDecl($context);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $bufPtr,
            $src,
            $context->builder->intCast($lenClamped, $sizeT)
        );
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->gep($bufPtr, $lenClamped)
        );

        $idSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(-1, false), $idSlot);
        foreach ($objectType->allDeclaredClassLowerNames() as $declLc) {
            $classId = $objectType->classIdForLowerName($declLc);
            if (null === $classId) {
                continue;
            }
            $litLen = \strlen($declLc);
            $lenOk = $context->builder->icmp(
                Builder::INT_EQ,
                $lenClamped,
                $i64->constInt($litLen, false)
            );
            $litCstr = $context->pointerFromStringConstant($declLc);
            $cmp = $context->builder->call(
                $strncasecmp,
                $bufPtr,
                $litCstr,
                $sizeT->constInt($litLen, false)
            );
            $charsOk = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $isMatch = $context->builder->and($lenOk, $charsOk);
            $cur = $context->builder->load($idSlot);
            $context->builder->store(
                $context->builder->select(
                    $isMatch,
                    $context->constantFromInteger($classId, 'int64'),
                    $cur
                ),
                $idSlot
            );
        }

        return self::emitWithRhsClassId($context, $expr, $context->builder->load($idSlot));
    }

    /** @return Value i8* pointing at {@see __string__}.value */
    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $map['value']),
            $i8p
        );
    }

    private static function emitWithRhsClassId(Context $context, Variable $expr, Value $rhsClassId): Variable
    {
        $objectType = $context->type->object;
        $i1 = $context->getTypeFromString('int1');
        $acc = $i1->constInt(0, false);
        foreach ($objectType->allClassNamesById() as $id => $name) {
            $isRhs = $context->builder->icmp(
                Builder::INT_EQ,
                $rhsClassId,
                $context->constantFromInteger($id, 'int64')
            );
            $check = $objectType->emitInstanceOf($expr, $name);
            $bool = self::nativeBoolValue($context, $check);
            $acc = $context->builder->select($isRhs, $bool, $acc);
        }

        return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $acc);
    }

    private static function nativeBoolValue(Context $context, Variable $var): Value
    {
        if (Variable::TYPE_NATIVE_BOOL === $var->type) {
            return $var->value;
        }

        return $context->helper->loadValue($var);
    }

    private static function emitInvalidClassRhsError(Context $context): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, self::ERROR_MESSAGE);
        $context->builder->call($context->lookupFunction('abort'));
    }

    /**
     * Shared with {@see ClassConstFetchHelper} / dynamic `new` / `$c::` (#30059).
     */
    public static function emitInvalidClassOperandError(Context $context): void
    {
        self::emitInvalidClassRhsError($context);
    }

    private static function ensureValueBoxBridgeLinked(Context $context): void
    {
        $abiName = '__instanceof__valueBoxRhsKind';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        // ensureBridge already restores insert; clearing left valueBoxRhsKind parentless (#32766).
        JitVmHelperLink::ensureBridge(
            $context,
            $abiName,
            'instanceof_value_box_rhs_kind_entry',
            [$i8],
            $i32,
            self::VALUE_BOX_RHS_KIND_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#10078'
        );
    }

    private static function callValueBoxRhsKind(Context $context, Value $typeByte): Value
    {
        self::ensureValueBoxBridgeLinked($context);
        $fn = $context->lookupFunction('__instanceof__valueBoxRhsKind');
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->call(
            $fn,
            $context->builder->trunc($typeByte, $i8)
        );
    }

    /**
     * Value-box type tag → instanceof RHS kind (string / object / invalid) (#30059).
     *
     * @return Value int32 {@see InstanceOfJitHelper::RHS_KIND_*}
     */
    public static function emitValueBoxClassOperandKind(Context $context, Variable $classVar): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $classVar);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );

        return self::callValueBoxRhsKind($context, $typeByte);
    }
}
