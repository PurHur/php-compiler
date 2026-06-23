<?php

declare(strict_types=1);

/**
 * LLVM json_encode helpers mirroring ext/standard/VmJson.php + VmJsonFormat.php (#6852).
 *
 * Replaces __compiler_json_encode_hashtable with value/array entry points for JIT/AOT.
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitArrayIsList;
use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\ext\standard\VmJsonFlags;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

final class StringJsonEncode
{
    public static function ensureLinked(Context $context): void
    {
        self::ensureLibc($context);
        self::implementIfMissing($context, '__compiler_json_encode_array', self::implementArray(...));
        self::implementIfMissing($context, '__compiler_json_encode_value', self::implementValue(...));
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);
        $dbl = $context->getTypeFromString('double');
        foreach (
            [
                ['isnan', $i32, [$dbl]],
                ['isinf', $i32, [$dbl]],
                ['strtod', $dbl, [$i8p, $i8pp]],
                ['strtol', $i64, [$i8p, $i8pp, $i32]],
            ] as [$name, $ret, $params]
        ) {
            if (null === $context->module->getNamedFunction($name)) {
                $fn = $context->module->addFunction($name, $context->context->functionType($ret, false, ...$params));
                $context->registerFunction($name, $fn);
            }
        }
    }

    /**
     * @param callable(Context): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $emit($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function implementValue(Context $context): void
    {
        $fn = $context->lookupFunction('__compiler_json_encode_value');
        $entry = $fn->appendBasicBlock('je_val_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $lastErr = StringJsonDecodeJit::ensureLastErrorGlobal($context);
        $context->builder->store($i32->constInt(0, false), $lastErr);

        $valPtr = $fn->getParam(0);
        $flags = $fn->getParam(1);
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $isArray = $context->builder->icmp(Builder::INT_NE, $ht, $htPtrTy->constNull());
        $arrayBb = $fn->appendBasicBlock('je_val_array');
        $scalarBb = $fn->appendBasicBlock('je_val_scalar');
        $context->builder->branchIf($isArray, $arrayBb, $scalarBb);

        $context->builder->positionAtEnd($arrayBb);
        $arrayResult = $context->builder->call(
            $context->lookupFunction('__compiler_json_encode_array'),
            $ht,
            $flags
        );
        $context->builder->returnValue($arrayResult);

        $context->builder->positionAtEnd($scalarBb);
        $scalarResult = self::emitScalarJson($context, $fn, $valPtr, $flags);
        $context->builder->returnValue($scalarResult);
        $context->builder->clearInsertionPosition();
    }

    private static function implementArray(Context $context): void
    {
        $fn = $context->lookupFunction('__compiler_json_encode_array');
        $entry = $fn->appendBasicBlock('je_arr_entry');
        $context->builder->positionAtEnd($entry);

        $ht = $fn->getParam(0);
        $flags = $fn->getParam(1);
        $isList = JitArrayIsList::hashTableIsList($context, $ht);
        $forceObject = self::flagIsSet($context, $flags, VmJsonFlags::FORCE_OBJECT);
        $forceObjList = $context->builder->and($isList, $forceObject);
        $listBb = $fn->appendBasicBlock('je_arr_list');
        $forceObjBb = $fn->appendBasicBlock('je_arr_force_obj');
        $assocBb = $fn->appendBasicBlock('je_arr_assoc');
        $checkListBb = $fn->appendBasicBlock('je_arr_check_list');
        $context->builder->branchIf($forceObjList, $forceObjBb, $checkListBb);

        $context->builder->positionAtEnd($checkListBb);
        $context->builder->branchIf($isList, $listBb, $assocBb);

        $context->builder->positionAtEnd($listBb);
        $listResult = self::emitListArrayJson($context, $fn, $ht, $flags);
        $context->builder->returnValue($listResult);

        $context->builder->positionAtEnd($forceObjBb);
        $forceObjResult = self::emitListAsObjectJson($context, $fn, $ht, $flags);
        $context->builder->returnValue($forceObjResult);

        $context->builder->positionAtEnd($assocBb);
        $assocResult = self::emitAssocArrayJson($context, $fn, $ht, $flags);
        $context->builder->returnValue($assocResult);
        $context->builder->clearInsertionPosition();
    }

    private static function emitListArrayJson(Context $context, LlvmFunction $fn, Value $ht, Value $flags): Value
    {
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $zeroSize = $sizeT->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);

        $resultSlot = $context->builder->alloca($strPtr, 1, 'je_list_acc');
        $firstSlot = $context->builder->alloca($i8, 1, 'je_list_first');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'je_list_i');
        $finalSlot = $context->builder->alloca($strPtr, 1, 'je_list_final');

        $nextFree = $context->builder->load($context->builder->structGep($ht, $htMap['nextFreeElement']));
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zeroSize);
        $emptyBb = $fn->appendBasicBlock('je_list_empty');
        $workBb = $fn->appendBasicBlock('je_list_work');
        $returnBb = $fn->appendBasicBlock('je_list_return');
        $context->builder->branchIf($isEmpty, $emptyBb, $workBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->store(self::literalString($context, '[]'), $finalSlot);
        $context->builder->branch($returnBb);

        $context->builder->positionAtEnd($workBb);
        $context->builder->store(self::literalString($context, '['), $resultSlot);
        $context->builder->store($i8->constInt(1, false), $firstSlot);
        $context->builder->store($zeroSize, $idxSlot);

        $headBb = $fn->appendBasicBlock('je_list_head');
        $bodyBb = $fn->appendBasicBlock('je_list_body');
        $doneBb = $fn->appendBasicBlock('je_list_done');
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($headBb);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $values = $context->builder->load($context->builder->structGep($ht, $htMap['values']));
        $entryPtr = $context->builder->inBoundsGEP($values, $idx);
        $kind = self::loadValueKind($context, $entryPtr);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $emitBb = $fn->appendBasicBlock('je_list_emit');
        $nextBb = $fn->appendBasicBlock('je_list_next');
        $context->builder->branchIf($isNull, $nextBb, $emitBb);

        $context->builder->positionAtEnd($emitBb);
        $acc = $context->builder->load($resultSlot);
        $isFirst = $context->builder->load($firstSlot);
        $notFirst = $context->builder->icmp(Builder::INT_EQ, $isFirst, $i8->constInt(0, false));
        $commaBb = $fn->appendBasicBlock('je_list_comma');
        $afterCommaBb = $fn->appendBasicBlock('je_list_after_comma');
        $context->builder->branchIf($notFirst, $commaBb, $afterCommaBb);
        $context->builder->positionAtEnd($commaBb);
        $acc = JitStringConcat::concat($context, $acc, self::literalString($context, ','));
        $context->builder->store($acc, $resultSlot);
        $context->builder->branch($afterCommaBb);
        $context->builder->positionAtEnd($afterCommaBb);
        $context->builder->store($i8->constInt(0, false), $firstSlot);

        $encoded = $context->builder->call(
            $context->lookupFunction('__compiler_json_encode_value'),
            $entryPtr,
            $flags
        );
        $acc = $context->builder->load($resultSlot);
        $context->builder->store(JitStringConcat::concat($context, $acc, $encoded), $resultSlot);
        $context->builder->branch($nextBb);

        $context->builder->positionAtEnd($nextBb);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $oneSize), $idxSlot);
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($doneBb);
        $acc = $context->builder->load($resultSlot);
        $context->builder->store(
            JitStringConcat::concat($context, $acc, self::literalString($context, ']')),
            $finalSlot
        );
        $context->builder->branch($returnBb);

        $context->builder->positionAtEnd($returnBb);

        return $context->builder->load($finalSlot);
    }

    private static function emitListAsObjectJson(Context $context, LlvmFunction $fn, Value $ht, Value $flags): Value
    {
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $zeroSize = $sizeT->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);

        $resultSlot = $context->builder->alloca($strPtr, 1, 'je_fo_acc');
        $firstSlot = $context->builder->alloca($i8, 1, 'je_fo_first');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'je_fo_i');
        $finalSlot = $context->builder->alloca($strPtr, 1, 'je_fo_final');

        $nextFree = $context->builder->load($context->builder->structGep($ht, $htMap['nextFreeElement']));
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zeroSize);
        $emptyBb = $fn->appendBasicBlock('je_fo_empty');
        $workBb = $fn->appendBasicBlock('je_fo_work');
        $returnBb = $fn->appendBasicBlock('je_fo_return');
        $context->builder->branchIf($isEmpty, $emptyBb, $workBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->store(self::literalString($context, '{}'), $finalSlot);
        $context->builder->branch($returnBb);

        $context->builder->positionAtEnd($workBb);
        $context->builder->store(self::literalString($context, '{'), $resultSlot);
        $context->builder->store($i8->constInt(1, false), $firstSlot);
        $context->builder->store($zeroSize, $idxSlot);

        $headBb = $fn->appendBasicBlock('je_fo_head');
        $bodyBb = $fn->appendBasicBlock('je_fo_body');
        $doneBb = $fn->appendBasicBlock('je_fo_done');
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($headBb);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $values = $context->builder->load($context->builder->structGep($ht, $htMap['values']));
        $entryPtr = $context->builder->inBoundsGEP($values, $idx);
        $kind = self::loadValueKind($context, $entryPtr);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $emitBb = $fn->appendBasicBlock('je_fo_emit');
        $nextBb = $fn->appendBasicBlock('je_fo_next');
        $context->builder->branchIf($isNull, $nextBb, $emitBb);

        $context->builder->positionAtEnd($emitBb);
        $acc = $context->builder->load($resultSlot);
        $isFirst = $context->builder->load($firstSlot);
        $notFirst = $context->builder->icmp(Builder::INT_EQ, $isFirst, $i8->constInt(0, false));
        $commaBb = $fn->appendBasicBlock('je_fo_comma');
        $afterCommaBb = $fn->appendBasicBlock('je_fo_after_comma');
        $context->builder->branchIf($notFirst, $commaBb, $afterCommaBb);
        $context->builder->positionAtEnd($commaBb);
        $acc = JitStringConcat::concat($context, $acc, self::literalString($context, ','));
        $context->builder->store($acc, $resultSlot);
        $context->builder->branch($afterCommaBb);
        $context->builder->positionAtEnd($afterCommaBb);
        $context->builder->store($i8->constInt(0, false), $firstSlot);

        $quotedKey = self::indexKeyString($context, $idx);
        $acc = $context->builder->load($resultSlot);
        $acc = JitStringConcat::concat($context, $acc, $quotedKey);
        $acc = JitStringConcat::concat($context, $acc, self::literalString($context, ':'));
        $encoded = $context->builder->call(
            $context->lookupFunction('__compiler_json_encode_value'),
            $entryPtr,
            $flags
        );
        $acc = JitStringConcat::concat($context, $acc, $encoded);
        $context->builder->store($acc, $resultSlot);
        $context->builder->branch($nextBb);

        $context->builder->positionAtEnd($nextBb);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $oneSize), $idxSlot);
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($doneBb);
        $acc = $context->builder->load($resultSlot);
        $context->builder->store(
            JitStringConcat::concat($context, $acc, self::literalString($context, '}')),
            $finalSlot
        );
        $context->builder->branch($returnBb);

        $context->builder->positionAtEnd($returnBb);

        return $context->builder->load($finalSlot);
    }

    private static function emitAssocArrayJson(Context $context, LlvmFunction $fn, Value $ht, Value $flags): Value
    {
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $valMap = $context->structFieldMap['__value__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroSize = $sizeT->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);
        $ptrSize = $sizeT->constInt(8, false);

        $countSlot = $context->builder->alloca($sizeT, 1, 'je_count');
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'je_walk');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'je_fill_idx');
        $emitIdxSlot = $context->builder->alloca($sizeT, 1, 'je_emit_idx');
        $firstSlot = $context->builder->alloca($i8, 1, 'je_first');
        $resultSlot = $context->builder->alloca($strPtr, 1, 'je_acc');
        $finalSlot = $context->builder->alloca($strPtr, 1, 'je_final');
        $nodesSlot = $context->builder->alloca($nodePtrType->pointerType(0), 1, 'je_nodes_buf');

        $head = $context->builder->load($context->builder->structGep($ht, $htMap['strKeys']));
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $head, $nodePtrType->constNull());
        $bbEmpty = $fn->appendBasicBlock('je_empty');
        $bbWork = $fn->appendBasicBlock('je_work');
        $bbReturn = $fn->appendBasicBlock('je_return');
        $context->builder->branchIf($isEmpty, $bbEmpty, $bbWork);

        $context->builder->positionAtEnd($bbEmpty);
        $context->builder->store(self::literalString($context, '{}'), $finalSlot);
        $context->builder->branch($bbReturn);

        $context->builder->positionAtEnd($bbWork);
        $context->builder->store($zeroSize, $countSlot);
        $context->builder->store($head, $walkSlot);
        $countHead = $fn->appendBasicBlock('je_count_head');
        $countBody = $fn->appendBasicBlock('je_count_body');
        $countDone = $fn->appendBasicBlock('je_count_done');
        $context->builder->branch($countHead);
        $context->builder->positionAtEnd($countHead);
        $walkNode = $context->builder->load($walkSlot);
        $walkEnd = $context->builder->icmp(Builder::INT_EQ, $walkNode, $nodePtrType->constNull());
        $context->builder->branchIf($walkEnd, $countDone, $countBody);
        $context->builder->positionAtEnd($countBody);
        $count = $context->builder->load($countSlot);
        $context->builder->store($context->builder->addNoSignedWrap($count, $oneSize), $countSlot);
        $nextWalk = $context->builder->load($context->builder->structGep($walkNode, $nodeMap['next']));
        $context->builder->store($nextWalk, $walkSlot);
        $context->builder->branch($countHead);
        $context->builder->positionAtEnd($countDone);
        $numKeys = $context->builder->load($countSlot);
        $bytes = $context->builder->mulNoSignedWrap($numKeys, $ptrSize);
        $nodesRaw = $context->builder->call($context->lookupFunction('__mm__malloc'), $bytes);
        $nodesArray = $context->builder->pointerCast($nodesRaw, $nodePtrType->pointerType(0));
        $context->builder->store($nodesArray, $nodesSlot);
        $context->builder->store($zeroSize, $idxSlot);
        $context->builder->store($head, $walkSlot);
        $fillHead = $fn->appendBasicBlock('je_fill_head');
        $fillBody = $fn->appendBasicBlock('je_fill_body');
        $fillDone = $fn->appendBasicBlock('je_fill_done');
        $context->builder->branch($fillHead);
        $context->builder->positionAtEnd($fillHead);
        $fillNode = $context->builder->load($walkSlot);
        $fillEnd = $context->builder->icmp(Builder::INT_EQ, $fillNode, $nodePtrType->constNull());
        $context->builder->branchIf($fillEnd, $fillDone, $fillBody);
        $context->builder->positionAtEnd($fillBody);
        $idx = $context->builder->load($idxSlot);
        $nodesArray = $context->builder->load($nodesSlot);
        $slotPtr = $context->builder->inBoundsGEP($nodesArray, $idx);
        $context->builder->store($fillNode, $slotPtr);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $oneSize), $idxSlot);
        $nextFill = $context->builder->load($context->builder->structGep($fillNode, $nodeMap['next']));
        $context->builder->store($nextFill, $walkSlot);
        $context->builder->branch($fillHead);
        $context->builder->positionAtEnd($fillDone);

        $context->builder->store(self::literalString($context, '{'), $resultSlot);
        $context->builder->store($i8->constInt(1, false), $firstSlot);
        $context->builder->store($zeroSize, $emitIdxSlot);

        $emitHead = $fn->appendBasicBlock('je_emit_head');
        $emitBody = $fn->appendBasicBlock('je_emit_body');
        $emitDone = $fn->appendBasicBlock('je_emit_done');
        $context->builder->branch($emitHead);
        $context->builder->positionAtEnd($emitHead);
        $emitIdx = $context->builder->load($emitIdxSlot);
        $emitEnd = $context->builder->icmp(Builder::INT_SGE, $emitIdx, $numKeys);
        $context->builder->branchIf($emitEnd, $emitDone, $emitBody);

        $context->builder->positionAtEnd($emitBody);
        $nodesArray = $context->builder->load($nodesSlot);
        $nodePtr = $context->builder->load($context->builder->inBoundsGEP($nodesArray, $emitIdx));
        $context->builder->store($context->builder->addNoSignedWrap($emitIdx, $oneSize), $emitIdxSlot);

        $acc = $context->builder->load($resultSlot);
        $isFirst = $context->builder->load($firstSlot);
        $notFirst = $context->builder->icmp(Builder::INT_EQ, $isFirst, $i8->constInt(0, false));
        $bbComma = $fn->appendBasicBlock('je_comma');
        $bbAfterComma = $fn->appendBasicBlock('je_after_comma');
        $context->builder->branchIf($notFirst, $bbComma, $bbAfterComma);
        $context->builder->positionAtEnd($bbComma);
        $acc = JitStringConcat::concat($context, $acc, self::literalString($context, ','));
        $context->builder->store($acc, $resultSlot);
        $context->builder->branch($bbAfterComma);
        $context->builder->positionAtEnd($bbAfterComma);
        $context->builder->store($i8->constInt(0, false), $firstSlot);

        $nodeKey = $context->builder->load($context->builder->structGep($nodePtr, $nodeMap['key']));
        $quotedKey = self::quoteString($context, $nodeKey);
        $acc = $context->builder->load($resultSlot);
        $acc = JitStringConcat::concat($context, $acc, $quotedKey);
        $acc = JitStringConcat::concat($context, $acc, self::literalString($context, ':'));
        $valField = $context->builder->structGep($nodePtr, $nodeMap['value']);
        $encodedVal = $context->builder->call(
            $context->lookupFunction('__compiler_json_encode_value'),
            $context->builder->pointerCast($valField, $context->getTypeFromString('__value__*')),
            $flags
        );
        $acc = JitStringConcat::concat($context, $acc, $encodedVal);
        $context->builder->store($acc, $resultSlot);
        $context->builder->branch($emitHead);

        $context->builder->positionAtEnd($emitDone);
        $nodesArray = $context->builder->load($nodesSlot);
        $nodesRaw = $context->builder->pointerCast($nodesArray, $context->getTypeFromString('int8*'));
        $context->builder->call($context->lookupFunction('__mm__free'), $nodesRaw);
        $acc = $context->builder->load($resultSlot);
        $context->builder->store(
            JitStringConcat::concat($context, $acc, self::literalString($context, '}')),
            $finalSlot
        );
        $context->builder->branch($bbReturn);

        $context->builder->positionAtEnd($bbReturn);

        return $context->builder->load($finalSlot);
    }

    private static function emitScalarJson(Context $context, LlvmFunction $fn, Value $valPtr, Value $flags): Value
    {
        $valMap = $context->structFieldMap['__value__'];
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI64 = $i64->constInt(0, false);

        $resultSlot = $context->builder->alloca($strPtr, 1, 'je_scalar_out');
        $numBuf = $context->builder->alloca($i8, $i64->constInt(32, false), 'je_scalar_numbuf');
        $typeByte = $context->builder->load($context->builder->structGep($valPtr, $valMap['type']));

        $nullType = $i8->constInt(Variable::TYPE_NULL, false);
        $longType = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
        $boolType = $i8->constInt(Variable::TYPE_NATIVE_BOOL, false);
        $doubleType = $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false);
        $stringType = $i8->constInt(Variable::TYPE_STRING & 0xff, false);
        $enumCaseType = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);

        $bbNull = $fn->appendBasicBlock('je_scalar_null');
        $bbCheckLong = $fn->appendBasicBlock('je_scalar_check_long');
        $bbLong = $fn->appendBasicBlock('je_scalar_long');
        $bbCheckBool = $fn->appendBasicBlock('je_scalar_check_bool');
        $bbBool = $fn->appendBasicBlock('je_scalar_bool');
        $bbCheckDouble = $fn->appendBasicBlock('je_scalar_check_double');
        $bbDouble = $fn->appendBasicBlock('je_scalar_double');
        $bbCheckString = $fn->appendBasicBlock('je_scalar_check_string');
        $bbString = $fn->appendBasicBlock('je_scalar_string');
        $bbCheckEnum = $fn->appendBasicBlock('je_scalar_check_enum');
        $bbEnum = $fn->appendBasicBlock('je_scalar_enum');
        $bbDefault = $fn->appendBasicBlock('je_scalar_default');
        $bbDone = $fn->appendBasicBlock('je_scalar_done');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullType);
        $context->builder->branchIf($isNull, $bbNull, $bbCheckLong);

        $context->builder->positionAtEnd($bbNull);
        $context->builder->store(self::literalString($context, 'null'), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckLong);
        $isLong = $context->builder->icmp(Builder::INT_EQ, $typeByte, $longType);
        $context->builder->branchIf($isLong, $bbLong, $bbCheckBool);

        $context->builder->positionAtEnd($bbCheckBool);
        $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolType);
        $context->builder->branchIf($isBool, $bbBool, $bbCheckDouble);

        $context->builder->positionAtEnd($bbCheckDouble);
        $isDouble = $context->builder->icmp(Builder::INT_EQ, $typeByte, $doubleType);
        $context->builder->branchIf($isDouble, $bbDouble, $bbCheckString);

        $context->builder->positionAtEnd($bbCheckString);
        $isStr = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringType);
        $context->builder->branchIf($isStr, $bbString, $bbCheckEnum);

        $context->builder->positionAtEnd($bbCheckEnum);
        $isEnum = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseType);
        $context->builder->branchIf($isEnum, $bbEnum, $bbDefault);

        $context->builder->positionAtEnd($bbDefault);
        $context->builder->store(self::literalString($context, 'null'), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbLong);
        $num = $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr);
        $bufC = $context->builder->pointerCast($numBuf, $i8p);
        $fmt = $context->builder->pointerCast($context->constantFromString('%lld'), $i8p);
        $context->builder->call($context->lookupFunction('sprintf'), $bufC, $fmt, $num);
        $len = $context->builder->call($context->lookupFunction('strlen'), $bufC);
        $lenI64 = $len->typeOf() === $i64 ? $len : $context->builder->zExt($len, $i64);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__string__init'), $lenI64, $bufC),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbBool);
        $valueField = $context->builder->structGep($valPtr, $valMap['value']);
        $boolByte = $context->builder->load(
            $context->builder->inBoundsGEP($valueField, $i32->constInt(0, false), $zeroI64)
        );
        $isTrue = $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false));
        $boolStr = $context->builder->select(
            $isTrue,
            self::literalString($context, 'true'),
            self::literalString($context, 'false')
        );
        $context->builder->store($boolStr, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDouble);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valPtr);
        $isNan = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('isnan'), $doubleVal),
            $i32->constInt(0, false)
        );
        $isInf = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('isinf'), $doubleVal),
            $i32->constInt(0, false)
        );
        $nonFinite = $context->builder->or($isNan, $isInf);
        $bbDoubleFail = $fn->appendBasicBlock('je_scalar_double_fail');
        $bbDoubleOk = $fn->appendBasicBlock('je_scalar_double_ok');
        $context->builder->branchIf($nonFinite, $bbDoubleFail, $bbDoubleOk);

        $context->builder->positionAtEnd($bbDoubleFail);
        $lastErr = StringJsonDecodeJit::ensureLastErrorGlobal($context);
        $context->builder->store(
            $i32->constInt(StringJsonDecodeJit::errorInfOrNan(), false),
            $lastErr
        );
        $context->builder->store($strPtr->constNull(), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDoubleOk);
        $bufC = $context->builder->pointerCast($numBuf, $i8p);
        $fmt = $context->builder->pointerCast($context->constantFromString('%.16G'), $i8p);
        $context->builder->call($context->lookupFunction('sprintf'), $bufC, $fmt, $doubleVal);
        $len = $context->builder->call($context->lookupFunction('strlen'), $bufC);
        $lenI64 = $len->typeOf() === $i64 ? $len : $context->builder->zExt($len, $i64);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__string__init'), $lenI64, $bufC),
            $resultSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbString);
        $raw = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $context->builder->store(self::encodeStringForJson($context, $fn, $raw, $flags), $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbEnum);
        $enumMap = $context->structFieldMap['__enum_case__'] ?? null;
        if (null !== $enumMap && isset($enumMap['backing'])) {
            $backingField = $context->builder->structGep($valPtr, $enumMap['backing']);
            $backingEncoded = $context->builder->call(
                $context->lookupFunction('__compiler_json_encode_value'),
                $context->builder->pointerCast($backingField, $context->getTypeFromString('__value__*')),
                $flags
            );
            $context->builder->store($backingEncoded, $resultSlot);
        } else {
            $context->builder->store($context->getTypeFromString('__string__*')->constNull(), $resultSlot);
        }
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return $context->builder->load($resultSlot);
    }

    private static function loadValueKind(Context $context, Value $entryPtr): Value
    {
        $valMap = $context->structFieldMap['__value__'];

        return $context->builder->load($context->builder->structGep($entryPtr, $valMap['type']));
    }

    private static function flagIsSet(Context $context, Value $flags, int $bit): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $masked = $context->builder->and($flags, $i64->constInt($bit, false));

        return $context->builder->icmp(Builder::INT_NE, $masked, $i64->constInt(0, false));
    }

    private static function indexKeyString(Context $context, Value $idx): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $numBuf = $context->builder->alloca($i8, $i64->constInt(32, false), 'je_idx_buf');
        $bufC = $context->builder->pointerCast($numBuf, $i8p);
        $fmt = $context->builder->pointerCast($context->constantFromString('%llu'), $i8p);
        $idxI64 = $idx->typeOf() === $i64 ? $idx : $context->builder->zExt($idx, $i64);
        $context->builder->call($context->lookupFunction('sprintf'), $bufC, $fmt, $idxI64);
        $len = $context->builder->call($context->lookupFunction('strlen'), $bufC);
        $lenI64 = $len->typeOf() === $i64 ? $len : $context->builder->zExt($len, $i64);
        $keyStr = $context->builder->call($context->lookupFunction('__string__init'), $lenI64, $bufC);

        return self::quoteString($context, $keyStr);
    }

    private static function literalString(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->getTypeFromString('int64')->constInt(strlen($text), false);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $context->builder->pointerCast($context->constantFromString($text), $i8p)
        );
    }

    private static function quoteString(Context $context, Value $str): Value
    {
        $open = self::literalString($context, '"');
        $close = self::literalString($context, '"');
        $quoted = JitStringConcat::concat($context, $open, $str);

        return JitStringConcat::concat($context, $quoted, $close);
    }

    /**
     * Encode __string__* for json_encode — honors JSON_NUMERIC_CHECK (php-src php_json_is_numeric_string).
     */
    private static function encodeStringForJson(Context $context, LlvmFunction $fn, Value $raw, Value $flags): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);
        $dbl = $context->getTypeFromString('double');
        $strMap = $context->structFieldMap['__string__'];
        $zeroI64 = $i64->constInt(0, false);
        $tenI32 = $i32->constInt(10, false);

        $outSlot = $context->builder->alloca($strPtr, 1, 'je_str_out');
        $useNumeric = self::flagIsSet($context, $flags, VmJsonFlags::NUMERIC_CHECK);
        $bbTry = $fn->appendBasicBlock('je_str_try_numeric');
        $bbQuote = $fn->appendBasicBlock('je_str_quote_path');
        $bbDone = $fn->appendBasicBlock('je_str_encode_done');
        $context->builder->branchIf($useNumeric, $bbTry, $bbQuote);

        $context->builder->positionAtEnd($bbTry);
        $len = $context->builder->load($context->builder->structGep($raw, $strMap['length']));
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zeroI64);
        $bbAfterEmpty = $fn->appendBasicBlock('je_str_after_empty');
        $context->builder->branchIf($isEmpty, $bbQuote, $bbAfterEmpty);

        $context->builder->positionAtEnd($bbAfterEmpty);
        $charPtr = $context->builder->structGep($raw, $strMap['value']);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'je_str_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        $context->builder->call($context->lookupFunction('strtol'), $charPtr, $endPtrSlot, $tenI32);
        $endPtr = $context->builder->load($endPtrSlot);
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );
        $isIntNumeric = $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
        $bbInt = $fn->appendBasicBlock('je_str_int_numeric');
        $bbCheckDbl = $fn->appendBasicBlock('je_str_check_double');
        $context->builder->branchIf($isIntNumeric, $bbInt, $bbCheckDbl);

        $context->builder->positionAtEnd($bbInt);
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        $longVal = $context->builder->call($context->lookupFunction('strtol'), $charPtr, $endPtrSlot, $tenI32);
        $numBuf = $context->builder->alloca($i8, $i64->constInt(32, false), 'je_str_int_buf');
        $bufC = $context->builder->pointerCast($numBuf, $i8p);
        $fmt = $context->builder->pointerCast($context->constantFromString('%lld'), $i8p);
        $context->builder->call($context->lookupFunction('sprintf'), $bufC, $fmt, $longVal);
        $bufLen = $context->builder->call($context->lookupFunction('strlen'), $bufC);
        $bufLenI64 = $bufLen->typeOf() === $i64 ? $bufLen : $context->builder->zExt($bufLen, $i64);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__string__init'), $bufLenI64, $bufC),
            $outSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckDbl);
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        $dblVal = $context->builder->call($context->lookupFunction('strtod'), $charPtr, $endPtrSlot);
        $endPtr = $context->builder->load($endPtrSlot);
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );
        $isDblNumeric = $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
        $bbDbl = $fn->appendBasicBlock('je_str_double_numeric');
        $context->builder->branchIf($isDblNumeric, $bbDbl, $bbQuote);

        $context->builder->positionAtEnd($bbDbl);
        $isNan = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('isnan'), $dblVal),
            $i32->constInt(0, false)
        );
        $isInf = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('isinf'), $dblVal),
            $i32->constInt(0, false)
        );
        $nonFinite = $context->builder->or($isNan, $isInf);
        $bbDblFinite = $fn->appendBasicBlock('je_str_double_finite');
        $context->builder->branchIf($nonFinite, $bbQuote, $bbDblFinite);

        $context->builder->positionAtEnd($bbDblFinite);
        $trunc = $context->builder->fptosi($dblVal, $i64);
        $roundTrip = $context->builder->sitofp($trunc, $dbl);
        $isWhole = $context->builder->fcmp(Builder::REAL_OEQ, $dblVal, $roundTrip);
        $bbDblWhole = $fn->appendBasicBlock('je_str_double_whole');
        $bbDblFrac = $fn->appendBasicBlock('je_str_double_frac');
        $context->builder->branchIf($isWhole, $bbDblWhole, $bbDblFrac);

        $context->builder->positionAtEnd($bbDblWhole);
        $numBuf = $context->builder->alloca($i8, $i64->constInt(32, false), 'je_str_whole_buf');
        $bufC = $context->builder->pointerCast($numBuf, $i8p);
        $fmt = $context->builder->pointerCast($context->constantFromString('%lld'), $i8p);
        $context->builder->call($context->lookupFunction('sprintf'), $bufC, $fmt, $trunc);
        $bufLen = $context->builder->call($context->lookupFunction('strlen'), $bufC);
        $bufLenI64 = $bufLen->typeOf() === $i64 ? $bufLen : $context->builder->zExt($bufLen, $i64);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__string__init'), $bufLenI64, $bufC),
            $outSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDblFrac);
        $numBuf = $context->builder->alloca($i8, $i64->constInt(32, false), 'je_str_frac_buf');
        $bufC = $context->builder->pointerCast($numBuf, $i8p);
        $fmt = $context->builder->pointerCast($context->constantFromString('%.16G'), $i8p);
        $context->builder->call($context->lookupFunction('sprintf'), $bufC, $fmt, $dblVal);
        $bufLen = $context->builder->call($context->lookupFunction('strlen'), $bufC);
        $bufLenI64 = $bufLen->typeOf() === $i64 ? $bufLen : $context->builder->zExt($bufLen, $i64);
        $context->builder->store(
            $context->builder->call($context->lookupFunction('__string__init'), $bufLenI64, $bufC),
            $outSlot
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbQuote);
        $context->builder->store(self::quoteString($context, $raw), $outSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return $context->builder->load($outSlot);
    }
}
