<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM for array_replace_recursive / HashTable::replaceRecursiveCopy (#26977).
 *
 * Ports deleted C {@see __compiler_array_replace_recursive_overlay}
 * (phpc_array_replace_recursive.c / #6022) as a real recursive LLVM function so
 * nested string-key merges mutate in place like php-src
 * {@see php_array_replace_recursive} — not a compile-time-inlined addMissing-only stub.
 *
 * Initial copy: alloc + overlay (not CowLlvm::duplicate — Cow HTs are dim-OK but
 * exportKeyValuePairs-empty for NestedJIT json_encode Done-when).
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_replace_recursive)
 */
final class HashTableReplaceRecursiveLlvm
{
    private const OVERLAY_FN = '__hashtable__replaceRecursiveOverlay';

    /**
     * Mask IS_REFCOUNTED — do not treat VM TYPE_ARRAY (6) as HT (collides with TYPE_VALUE&0x7f) (#26977).
     */
    private static function isHashtableTypeByte(Context $context, Value $typeByte): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        return $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
    }

    /** NestedJIT / bridge: copy with zero overlays (alloc+overlay — exportable for json_encode). */
    public static function replaceSingle(Context $context, Value $ht): Value
    {
        // Prefer alloc+overlay over CowLlvm::duplicate — Cow copies are dim-fetchable but
        // exportKeyValuePairs-empty under NestedJIT json_encode (#26977 Done-when).
        $overlay = self::ensureOverlayFunction($context);
        $result = HashTableHelper::alloc($context);
        $context->builder->call($overlay, $result, $ht);

        return $result;
    }

    /** NestedJIT / bridge: duplicate base then overlay one replacement (C overlay parity). */
    public static function replaceTwo(Context $context, Value $left, Value $right): Value
    {
        $overlay = self::ensureOverlayFunction($context);
        $result = self::replaceSingle($context, $left);
        $context->builder->call($overlay, $result, $right);

        return $result;
    }

    /**
     * array_replace_recursive() — nested key merge (ext/standard/array.c parity; #3166).
     */
    public static function arrayReplaceRecursive(Context $context, Variable $first, Variable ...$others): Value
    {
        $overlay = self::ensureOverlayFunction($context);
        $result = self::replaceSingle(
            $context,
            ArrayBuiltinHelper::loadHashTable($context, $first)
        );
        foreach ($others as $other) {
            $context->builder->call(
                $overlay,
                $result,
                ArrayBuiltinHelper::loadHashTable($context, $other)
            );
        }

        return $result;
    }

    /**
     * Declare+implement recursive overlay once (C __compiler_array_replace_recursive_overlay).
     *
     * Sets {@see Context::$activeFunction} like {@see Builtin\HashTableDuplicateRuntime}
     * so BasicBlockHelper appends land on this function, not the caller.
     */
    public static function ensureOverlayFunction(Context $context): LlvmFunction
    {
        $probe = $context->module->getNamedFunction(self::OVERLAY_FN);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::OVERLAY_FN, $probe);

            return $probe;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $savedActive = $context->activeFunction;
        $savedLowering = $context->loweringLlvmFunction;

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                self::OVERLAY_FN,
                $context->context->functionType(
                    $context->context->voidType(),
                    false,
                    $htPtr,
                    $htPtr
                )
            );
        }
        // Register before body so recursive calls resolve.
        $context->registerFunction(self::OVERLAY_FN, $fn);
        $context->activeFunction = self::OVERLAY_FN;
        // Pin lowering owner so BasicBlockHelper::parentFunction does not redirect
        // appends to the caller's method under #31101 foreign-insert recovery.
        $context->loweringLlvmFunction = $fn instanceof \PHPLLVM\Value\Function_ ? $fn : null;

        $entry = $fn->appendBasicBlock('arr_replace_rec_overlay_entry');
        $context->builder->positionAtEnd($entry);
        try {
            self::emitOverlayBody($context, $fn);
        } finally {
            $context->activeFunction = $savedActive;
            $context->loweringLlvmFunction = $savedLowering;
            if (null !== $savedBlock) {
                BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
            } else {
                $context->builder->clearInsertionPosition();
            }
        }

        return $fn;
    }

    /**
     * void overlay(dest, src) — packed then string keys; both-HT → recursive call in place.
     */
    private static function emitOverlayBody(Context $context, LlvmFunction $fn): void
    {
        $dest = $fn->getParam(0);
        $src = $fn->getParam(1);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $valueMap = $context->structFieldMap['__value__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $valuePtrType = $context->getTypeFromString('__value__*');

        // --- packed indices ---
        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'arr_replace_rec_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'arr_replace_rec_packed_body');
        $packedSet = BasicBlockHelper::append($context, 'arr_replace_rec_packed_set');
        $packedNext = BasicBlockHelper::append($context, 'arr_replace_rec_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'arr_replace_rec_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedSet, $packedNext);

        $context->builder->positionAtEnd($packedSet);
        $destHas = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $dest,
            $idx
        );
        $srcVal = self::listEntryAt($context, $src, $idx);
        $srcIsHt = self::isHashtableTypeByte(
            $context,
            $context->builder->load($context->builder->structGep($srcVal, $valueMap['type']))
        );
        $destVal = self::listEntryAt($context, $dest, $idx);
        $destIsHt = self::isHashtableTypeByte(
            $context,
            $context->builder->load($context->builder->structGep($destVal, $valueMap['type']))
        );
        $bothHt = $context->builder->and(
            $destHas,
            $context->builder->and($srcIsHt, $destIsHt)
        );
        $packedCopy = BasicBlockHelper::append($context, 'arr_replace_rec_packed_copy');
        $packedMerge = BasicBlockHelper::append($context, 'arr_replace_rec_packed_merge');
        $context->builder->branchIf($bothHt, $packedMerge, $packedCopy);

        $context->builder->positionAtEnd($packedCopy);
        $srcElem = HashTableReadLlvm::readIndexedToValueBox($context, $src, $idx);
        HashTableWriteLlvm::setAtIndex($context, $dest, $idx, $srcElem);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedMerge);
        $existingHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $destVal
        );
        $overlayHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $srcVal
        );
        $context->builder->call($fn, $existingHt, $overlayHt);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        // --- string keys ---
        $strInit = BasicBlockHelper::append($context, 'arr_replace_rec_str_init');
        $strHead = BasicBlockHelper::append($context, 'arr_replace_rec_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = BasicBlockHelper::entryAlloca($context, $nodePtrType);
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'arr_replace_rec_str_body');
        $strSet = BasicBlockHelper::append($context, 'arr_replace_rec_str_set');
        $strNext = BasicBlockHelper::append($context, 'arr_replace_rec_str_next');
        $strDone = BasicBlockHelper::append($context, 'arr_replace_rec_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $context->builder->branch($strSet);

        $context->builder->positionAtEnd($strSet);
        $existingPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $dest,
            $keyStr
        );
        $existingNull = $context->builder->icmp(Builder::INT_EQ, $existingPtr, $valuePtrType->constNull());
        $strAdd = BasicBlockHelper::append($context, 'arr_replace_rec_str_add');
        $strReplace = BasicBlockHelper::append($context, 'arr_replace_rec_str_replace');
        $context->builder->branchIf($existingNull, $strAdd, $strReplace);

        $context->builder->positionAtEnd($strAdd);
        self::storeNodeValueAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strReplace);
        $srcIsHt2 = self::isHashtableTypeByte(
            $context,
            $context->builder->load($context->builder->structGep($valEntry, $valueMap['type']))
        );
        $existingIsHt = self::isHashtableTypeByte(
            $context,
            $context->builder->load($context->builder->structGep($existingPtr, $valueMap['type']))
        );
        $bothHt2 = $context->builder->and($srcIsHt2, $existingIsHt);
        $strScalar = BasicBlockHelper::append($context, 'arr_replace_rec_str_scalar');
        $strDeep = BasicBlockHelper::append($context, 'arr_replace_rec_str_deep');
        $context->builder->branchIf($bothHt2, $strDeep, $strScalar);

        $context->builder->positionAtEnd($strScalar);
        self::storeNodeValueAtStringKey($context, $dest, $keyStr, $valEntry);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strDeep);
        $existingHt2 = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $existingPtr
        );
        $overlayHt2 = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valEntry
        );
        $context->builder->call($fn, $existingHt2, $overlayHt2);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
        $context->builder->returnVoid();
    }

    private static function listEntryAt(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );

        return $context->builder->inBoundsGep($values, $index);
    }

    /** Copy node value into dest at string key (do not alias src node storage — #26977). */
    private static function storeNodeValueAtStringKey(
        Context $context,
        Value $dest,
        Value $keyStr,
        Value $valEntry
    ): void {
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $slot, $valEntry);
        $elem = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        HashTableWriteLlvm::setAtStringKey($context, $dest, $keyStr, $elem);
    }
}
