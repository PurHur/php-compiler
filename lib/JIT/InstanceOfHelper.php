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
 * instanceof lowering for literal and dynamic class operands (#4339, #10078, #32766, #32775).
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
        // Boxed / slotted locals (`$n = 'A'`) keep compileTimeString — fold like
        // method_exists/is_a (#32701 / #32706) instead of a runtime value-box read that
        // mis-lowers under thin AOT (#32766).
        $classLit = JitStringArg::compileTimeLiteral($classVar) ?? $classVar->compileTimeString;
        if (\is_string($classLit) && '' !== $classLit) {
            return $context->type->object->emitInstanceOf($expr, $classLit);
        }
        if (Variable::TYPE_STRING === $classVar->type) {
            return self::emitWithClassNameString(
                $context,
                $expr,
                JitStringArg::lower($context, $classVar, 'instanceof class')
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
        // Length-exact __string__ payloads: memcmp + length gate against declared names,
        // then emitWithRhsClassId (#32775). Avoid __compiler_strcasecmp — its stringFromCstr
        // bridge returns 0 for every pair when both sides are synthesized from i8* here.
        // Case-insensitive match: compare against the lowercase registry key (PHP class names
        // are case-insensitive) after ASCII-tolower of a stack snapshot.
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $nameLen = $context->builder->load($context->builder->structGep($classNameStr, $map['length']));
        $nameLenSize = $nameLen->typeOf() === $sizeT
            ? $nameLen
            : $context->builder->zExt($nameLen, $sizeT);
        $nameData = $context->builder->pointerCast(
            $context->builder->structGep($classNameStr, $map['value']),
            $i8p
        );

        LibcExtern::ensureMemcpyDecl($context);
        LibcExtern::ensureMemcmpDecl($context);

        $bufSize = 256;
        $buf = $context->builder->alloca($i8->arrayType($bufSize), 1, 'instanceof_class_name');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $maxCopy = $sizeT->constInt($bufSize - 1, false);
        $copyLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGE, $nameLenSize, $maxCopy),
            $maxCopy,
            $nameLenSize
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $bufPtr,
            $nameData,
            $copyLen
        );
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($bufPtr, $copyLen)
        );

        $fn = BasicBlockHelper::parentFunction($context);
        $lowerLoop = $fn->appendBasicBlock('instanceof_cname_tolower_loop');
        $lowerBody = $fn->appendBasicBlock('instanceof_cname_tolower_body');
        $lowerDone = $fn->appendBasicBlock('instanceof_cname_tolower_done');
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);
        $context->builder->branch($lowerLoop);
        $context->builder->positionAtEnd($lowerLoop);
        $idx = $context->builder->load($idxSlot);
        $cont = $context->builder->icmp(Builder::INT_ULT, $idx, $copyLen);
        $context->builder->branchIf($cont, $lowerBody, $lowerDone);
        $context->builder->positionAtEnd($lowerBody);
        $bytePtr = $context->builder->inBoundsGEP($bufPtr, $idx);
        $byte = $context->builder->load($bytePtr);
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_UGE, $byte, $i8->constInt(ord('A'), false)),
            $context->builder->icmp(Builder::INT_ULE, $byte, $i8->constInt(ord('Z'), false))
        );
        $lowered = $context->builder->add($byte, $i8->constInt(32, false));
        $context->builder->store(
            $context->builder->select($isUpper, $lowered, $byte),
            $bytePtr
        );
        $context->builder->store(
            $context->builder->add($idx, $sizeT->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($lowerLoop);
        $context->builder->positionAtEnd($lowerDone);

        $objectType = $context->type->object;
        $done = $fn->appendBasicBlock('instanceof_cname_done');
        $idSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(-1, false), $idSlot);

        $check = $lowerDone;
        $hasCase = false;
        foreach ($objectType->allDeclaredClassLowerNames() as $declLc) {
            $classId = $objectType->classIdForLowerName($declLc);
            if (null === $classId || '' === $declLc) {
                continue;
            }
            $hasCase = true;
            $case = $fn->appendBasicBlock('instanceof_cname_'.$classId);
            $next = $fn->appendBasicBlock('instanceof_cname_try_'.$classId);
            $context->builder->positionAtEnd($check);
            $expectedLen = \strlen($declLc);
            $lenEq = $context->builder->icmp(
                Builder::INT_EQ,
                $nameLenSize,
                $sizeT->constInt($expectedLen, false)
            );
            $cmp = $context->builder->call(
                $context->lookupFunction('memcmp'),
                $bufPtr,
                $context->pointerFromStringConstant($declLc),
                $nameLenSize
            );
            $strEq = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $isMatch = $context->builder->and($lenEq, $strEq);
            $context->builder->branchIf($isMatch, $case, $next);
            $context->builder->positionAtEnd($case);
            $context->builder->store(
                $context->constantFromInteger($classId, 'int64'),
                $idSlot
            );
            $context->builder->branch($done);
            $check = $next;
        }
        if (!$hasCase) {
            $context->builder->positionAtEnd($check);
            $context->builder->branch($done);
        } else {
            $context->builder->positionAtEnd($check);
            $context->builder->branch($done);
        }
        $context->builder->positionAtEnd($done);

        return self::emitWithRhsClassId(
            $context,
            $expr,
            $context->builder->load($idSlot)
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

        // Bridge emission repositions the builder; restore so the caller's trunc/call
        // stay inside the user function (#32766 parentless __instanceof__valueBoxRhsKind).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
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
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
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
