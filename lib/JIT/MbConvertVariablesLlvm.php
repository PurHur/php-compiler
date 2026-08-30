<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\MbConvertEncodingRuntime;
use PHPCompiler\JIT\Builtin\MbConvertVariablesRuntime;
use PHPCompiler\JIT\TypedPropertyUninitGuard;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Pure LLVM mb_convert_variables() array/object by-ref (#35315 leftover #4572).
 *
 * Walk bodies emit once per module (peer {@see ArrayWalkLlvm}) — inlining class dispatch
 * at every convertValueBox call site hangs compile.
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_variables)
 */
final class MbConvertVariablesLlvm
{
    private const ABI_ARRAY = '__mb_convert_variables_array';

    private const ABI_OBJECT = '__mb_convert_variables_object';

    private const ABI_VALUE = '__mb_convert_variables_value';

    private static int $seq = 0;

    public static function convertArrayInPlace(
        Context $context,
        Value $ht,
        Value $toPtr,
        Value $fromPtr,
        Value $lastDetectedSlot,
        Value $anyFailSlot
    ): void {
        MbConvertEncodingRuntime::ensureLinked($context);
        MbConvertVariablesRuntime::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mcv_array_call');
        $context->builder->call(
            self::ensureArrayFunction($context),
            $ht,
            $toPtr,
            $fromPtr,
            $lastDetectedSlot,
            $anyFailSlot
        );
    }

    public static function convertObjectInPlace(
        Context $context,
        Value $obj,
        Value $toPtr,
        Value $fromPtr,
        Value $lastDetectedSlot,
        Value $anyFailSlot
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mcv_object_call');
        $context->builder->call(
            self::ensureObjectFunction($context),
            $obj,
            $toPtr,
            $fromPtr,
            $lastDetectedSlot,
            $anyFailSlot
        );
    }

    private static function ensureArrayFunction(Context $context): LlvmFunction
    {
        return self::ensureWalkFunction($context, self::ABI_ARRAY, '__hashtable__*', static function (
            Context $context,
            LlvmFunction $fn
        ): void {
            $ht = $fn->getParam(0);
            $toPtr = $fn->getParam(1);
            $fromPtr = $fn->getParam(2);
            $lastDetectedSlot = $fn->getParam(3);
            $anyFailSlot = $fn->getParam(4);
            self::emitArrayWalk($context, $ht, $toPtr, $fromPtr, $lastDetectedSlot, $anyFailSlot);
            $context->builder->returnVoid();
        });
    }

    private static function ensureObjectFunction(Context $context): LlvmFunction
    {
        return self::ensureWalkFunction($context, self::ABI_OBJECT, '__object__*', static function (
            Context $context,
            LlvmFunction $fn
        ): void {
            $obj = $fn->getParam(0);
            $toPtr = $fn->getParam(1);
            $fromPtr = $fn->getParam(2);
            $lastDetectedSlot = $fn->getParam(3);
            $anyFailSlot = $fn->getParam(4);
            self::emitObjectWalk($context, $obj, $toPtr, $fromPtr, $lastDetectedSlot, $anyFailSlot);
            $context->builder->returnVoid();
        });
    }

    private static function ensureValueFunction(Context $context): LlvmFunction
    {
        return self::ensureWalkFunction($context, self::ABI_VALUE, '__value__*', static function (
            Context $context,
            LlvmFunction $fn
        ): void {
            $valuePtr = $fn->getParam(0);
            $toPtr = $fn->getParam(1);
            $fromPtr = $fn->getParam(2);
            $lastDetectedSlot = $fn->getParam(3);
            $anyFailSlot = $fn->getParam(4);
            self::emitValueDispatch($context, $valuePtr, $toPtr, $fromPtr, $lastDetectedSlot, $anyFailSlot);
            $context->builder->returnVoid();
        });
    }

    /**
     * @param callable(Context, LlvmFunction): void $emitBody
     */
    private static function ensureWalkFunction(
        Context $context,
        string $abi,
        string $firstParamType,
        callable $emitBody
    ): LlvmFunction {
        $probe = $context->module->getNamedFunction($abi);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abi, $probe);

            return $probe;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $strPtrPtr = $context->getTypeFromString('__string__**');
        $i1Ptr = $context->getTypeFromString('int1*');
        $firstTy = $context->getTypeFromString($firstParamType);
        $fn = $probe ?? $context->module->addFunction(
            $abi,
            $context->context->functionType(
                $context->context->voidType(),
                false,
                $firstTy,
                $strPtr,
                $strPtr,
                $strPtrPtr,
                $i1Ptr
            )
        );
        $context->registerFunction($abi, $fn);

        BasicBlockHelper::scopeLoweringToFunction($context, $fn, $abi, static function () use ($context, $fn, $emitBody): void {
            $entry = $fn->appendBasicBlock('entry');
            $context->builder->positionAtEnd($entry);
            $emitBody($context, $fn);
        });

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }

        return $fn;
    }

    private static function emitArrayWalk(
        Context $context,
        Value $ht,
        Value $toPtr,
        Value $fromPtr,
        Value $lastDetectedSlot,
        Value $anyFailSlot
    ): void {
        self::walkPacked($context, $ht, $toPtr, $fromPtr, $lastDetectedSlot, $anyFailSlot);
        self::walkStringKeys($context, $ht, $toPtr, $fromPtr, $lastDetectedSlot, $anyFailSlot);
    }

    private static function emitObjectWalk(
        Context $context,
        Value $obj,
        Value $toPtr,
        Value $fromPtr,
        Value $lastDetectedSlot,
        Value $anyFailSlot
    ): void {
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load($context->builder->structGep($obj, $objMap['class_id']));
        $fn = $context->builder->getInsertBlock()->getParent();
        $done = $fn->appendBasicBlock('mcv_obj_done_'.(++self::$seq));
        $entry = $context->builder->getInsertBlock();
        $checkBlock = $entry;
        foreach ($context->type->object->allClassNamesById() as $id => $className) {
            if ($checkBlock !== $entry) {
                $context->builder->positionAtEnd($checkBlock);
            }
            $isClass = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $context->constantFromInteger($id, 'int64')
            );
            $matchBlock = $fn->appendBasicBlock('mcv_obj_class_'.$id);
            $nextCheck = $fn->appendBasicBlock('mcv_obj_next_'.$id);
            $context->builder->branchIf($isClass, $matchBlock, $nextCheck);
            $context->builder->positionAtEnd($matchBlock);
            self::emitClassPropertyWalk(
                $context,
                $obj,
                $className,
                $toPtr,
                $fromPtr,
                $lastDetectedSlot,
                $anyFailSlot
            );
            $context->builder->branch($done);
            $checkBlock = $nextCheck;
        }
        $context->builder->positionAtEnd($checkBlock);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function emitClassPropertyWalk(
        Context $context,
        Value $obj,
        string $className,
        Value $toPtr,
        Value $fromPtr,
        Value $lastDetectedSlot,
        Value $anyFailSlot
    ): void {
        $classId = $context->type->object->lookup(strtolower(ltrim($className, '\\')));
        $props = $context->type->object->instancePropertySetsVisibleFromScope($classId, null);
        if ([] === $props) {
            return;
        }
        foreach ($props as $propset) {
            $propVar = $context->type->object->propertyFetch($obj, $className, $propset[1], true);
            TypedPropertyUninitGuard::emitBeforeRead($context, $propVar);
            $valuePtr = JitValueBox::valuePtrForByRefReturn($context, $propVar);
            self::convertValueAtPtr(
                $context,
                $valuePtr,
                $toPtr,
                $fromPtr,
                $lastDetectedSlot,
                $anyFailSlot
            );
        }
    }

    private static function walkPacked(
        Context $context,
        Value $ht,
        Value $toPtr,
        Value $fromPtr,
        Value $lastDetectedSlot,
        Value $anyFailSlot
    ): void {
        $valueFn = self::ensureValueFunction($context);
        $tag = (string) (++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nextFree = $context->builder->load($context->builder->structGep($ht, $htMap['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'mcv_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'mcv_pk_body_'.$tag);
        $work = BasicBlockHelper::append($context, 'mcv_pk_work_'.$tag);
        $next = BasicBlockHelper::append($context, 'mcv_pk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'mcv_pk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $context->builder->branchIf($isSet, $work, $next);

        $context->builder->positionAtEnd($work);
        $entry = HashTableHelper::listEntryPointer($context, $ht, $idx);
        $context->builder->call(
            $valueFn,
            $entry,
            $toPtr,
            $fromPtr,
            $lastDetectedSlot,
            $anyFailSlot
        );
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function walkStringKeys(
        Context $context,
        Value $ht,
        Value $toPtr,
        Value $fromPtr,
        Value $lastDetectedSlot,
        Value $anyFailSlot
    ): void {
        $valueFn = self::ensureValueFunction($context);
        $tag = (string) (++self::$seq);
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');
        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($ht, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);
        $head = BasicBlockHelper::append($context, 'mcv_sk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'mcv_sk_body_'.$tag);
        $next = BasicBlockHelper::append($context, 'mcv_sk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'mcv_sk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $context->builder->call(
            $valueFn,
            $valField,
            $toPtr,
            $fromPtr,
            $lastDetectedSlot,
            $anyFailSlot
        );
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    public static function convertValueAtPtr(
        Context $context,
        Value $valuePtr,
        Value $toPtr,
        Value $fromPtr,
        Value $lastDetectedSlot,
        Value $anyFailSlot
    ): void {
        self::emitValueDispatch($context, $valuePtr, $toPtr, $fromPtr, $lastDetectedSlot, $anyFailSlot);
    }

    private static function emitValueDispatch(
        Context $context,
        Value $valuePtr,
        Value $toPtr,
        Value $fromPtr,
        Value $lastDetectedSlot,
        Value $anyFailSlot
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $tag = 'mcv_vb'.(++self::$seq);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $context->structFieldMap['__value__']['type'])
        );
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $strBlock = BasicBlockHelper::append($context, $tag.'_str');
        $arrBlock = BasicBlockHelper::append($context, $tag.'_arr');
        $objBlock = BasicBlockHelper::append($context, $tag.'_obj');
        $done = BasicBlockHelper::append($context, $tag.'_done');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_STRING & 0x7f, false)
        );
        $afterString = BasicBlockHelper::append($context, $tag.'_after_str');
        $context->builder->branchIf($isString, $strBlock, $afterString);

        $context->builder->positionAtEnd($afterString);
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ARRAY & 0x7f, false)
        );
        $afterArray = BasicBlockHelper::append($context, $tag.'_after_arr');
        $context->builder->branchIf($isArray, $arrBlock, $afterArray);

        $context->builder->positionAtEnd($afterArray);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_OBJECT & 0x7f, false)
        );
        $context->builder->branchIf($isObject, $objBlock, $done);

        $context->builder->positionAtEnd($strBlock);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        // Peer JitMbConvertVariables::lowerStringVar — convertStringHelper NestedJIT mis-returns (#35315).
        $converted = MbConvertEncodingRuntime::callConvert(
            $context,
            $str,
            $toPtr,
            $fromPtr
        );
        $detected = $context->builder->call(
            MbConvertVariablesRuntime::detectHelper($context),
            $str,
            $toPtr,
            $fromPtr
        );
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $converted);
        $context->builder->call($context->lookupFunction('__value__writeString'), $valuePtr, $owned);
        JitValueBox::publishAfterWrite($context, $valuePtr);
        self::mergeDetected($context, $detected, $lastDetectedSlot, $anyFailSlot);
        $context->builder->branch($done);

        $arrayFn = self::ensureArrayFunction($context);
        $objectFn = self::ensureObjectFunction($context);

        $context->builder->positionAtEnd($arrBlock);
        $nestedHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valuePtr
        );
        $context->builder->call(
            $arrayFn,
            $nestedHt,
            $toPtr,
            $fromPtr,
            $lastDetectedSlot,
            $anyFailSlot
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($objBlock);
        $nestedObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $context->builder->call(
            $objectFn,
            $nestedObj,
            $toPtr,
            $fromPtr,
            $lastDetectedSlot,
            $anyFailSlot
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function mergeDetected(
        Context $context,
        Value $detected,
        Value $lastDetectedSlot,
        Value $anyFailSlot
    ): void {
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $dlen = $context->builder->call($context->lookupFunction('__string__strlen'), $detected);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $dlen, $zero);
        $tag = 'mcv_md'.(++self::$seq);
        $emptyBlock = BasicBlockHelper::append($context, $tag.'_empty');
        $keepBlock = BasicBlockHelper::append($context, $tag.'_keep');
        $contBlock = BasicBlockHelper::append($context, $tag.'_cont');
        $context->builder->branchIf($isEmpty, $emptyBlock, $keepBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->store($i1->constInt(1, false), $anyFailSlot);
        $context->builder->branch($contBlock);

        $context->builder->positionAtEnd($keepBlock);
        $ownedDet = $context->builder->call($context->lookupFunction('__string__separate'), $detected);
        $context->builder->store($ownedDet, $lastDetectedSlot);
        $context->builder->branch($contBlock);

        $context->builder->positionAtEnd($contBlock);
    }
}
