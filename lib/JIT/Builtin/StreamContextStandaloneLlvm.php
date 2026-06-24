<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM stream_context ABI — AOT standalone only (#9340).
 *
 * JIT embed uses {@see StreamContextJitHelper} PHP; standalone keeps LLVM walker until
 * HashTable iteration compiles in native standalone nested link (same gate as #9443).
 * php-src: ext/standard/streams.c
 */
final class StreamContextStandaloneLlvm
{
    private static int $blockSerial = 0;

    private const GLOBAL_NEXT_ID = 'phpc_stream_context_next_id';

    private const GLOBAL_DEFAULT = 'phpc_stream_context_default';

    private const MARKER_KEY = '__phpc_stream_context';

    private const PARAMS_MARKER_KEY = '__phpc_stream_context_params';


    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_stream_context_create');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::ensureSetParamsIfMissing($context);
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureHashtableHelpers($context);
        self::ensureNextIdGlobal($context);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $voidTy = $context->getTypeFromString('void');

        $mergeProbe = $context->module->getNamedFunction('__phpc_stream_context_merge_options');
        $ftMerge = $context->context->functionType($voidTy, false, $htPtr, $htPtr);
        $fnMerge = null !== $mergeProbe
            ? $mergeProbe
            : $context->module->addFunction('__phpc_stream_context_merge_options', $ftMerge);
        self::implementMergeOptions($context, $fnMerge);

        $createProbe = $context->module->getNamedFunction('__phpc_stream_context_create');
        $ftCreate = $context->context->functionType($htPtr, false, $htPtr, $htPtr);
        $fnCreate = null !== $createProbe
            ? $createProbe
            : $context->module->addFunction('__phpc_stream_context_create', $ftCreate);
        self::implementCreate($context, $fnCreate, $fnMerge);

        $getOptsProbe = $context->module->getNamedFunction('__phpc_stream_context_get_options');
        $ftGetOpts = $context->context->functionType($htPtr, false, $htPtr);
        $fnGetOpts = null !== $getOptsProbe
            ? $getOptsProbe
            : $context->module->addFunction('__phpc_stream_context_get_options', $ftGetOpts);
        self::implementGetOptions($context, $fnGetOpts, $fnMerge);

        $setParamsProbe = $context->module->getNamedFunction('__phpc_stream_context_set_params');
        $ftSetParams = $context->context->functionType($voidTy, false, $htPtr, $htPtr);
        $fnSetParams = null !== $setParamsProbe
            ? $setParamsProbe
            : $context->module->addFunction('__phpc_stream_context_set_params', $ftSetParams);
        self::implementSetParams($context, $fnSetParams, $fnMerge);

        self::registerLinkedRuntime($context);
    }

    private static function ensureSetParamsIfMissing(Context $context): void
    {
        $setParamsProbe = $context->module->getNamedFunction('__phpc_stream_context_set_params');
        if (null !== $setParamsProbe && $setParamsProbe->countBasicBlocks() > 0) {
            return;
        }

        $fnMerge = $context->module->getNamedFunction('__phpc_stream_context_merge_options');
        if (null === $fnMerge || 0 === $fnMerge->countBasicBlocks()) {
            throw new \LogicException('StreamContextRuntime: __phpc_stream_context_merge_options missing');
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $voidTy = $context->getTypeFromString('void');
        $fnSetParams = null !== $setParamsProbe
            ? $setParamsProbe
            : $context->module->addFunction('__phpc_stream_context_set_params', $context->context->functionType($voidTy, false, $htPtr, $htPtr));
        self::implementSetParams($context, $fnSetParams, $fnMerge);
    }

    private static function implementCreate(Context $context, Value $fn, Value $fnMerge): void
    {
        $entry = $fn->appendBasicBlock('scc_entry');
        $context->builder->positionAtEnd($entry);

        $options = $fn->getParam(0);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullHt = $htPtr->constNull();
        $out = $context->builder->call($context->lookupFunction('__hashtable__alloc'));

        $hasOptions = $context->builder->icmp(Builder::INT_NE, $options, $nullHt);
        $mergeBb = $fn->appendBasicBlock('scc_merge');
        $afterMerge = $fn->appendBasicBlock('scc_after_merge');
        $context->builder->branchIf($hasOptions, $mergeBb, $afterMerge);

        $context->builder->positionAtEnd($mergeBb);
        $context->builder->call($fnMerge, $out, $options);
        $context->builder->branch($afterMerge);

        $context->builder->positionAtEnd($afterMerge);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $global = $context->module->getNamedGlobal(self::GLOBAL_NEXT_ID);
        if (null === $global) {
            throw new \LogicException('StreamContextRuntime: '.self::GLOBAL_NEXT_ID.' missing');
        }
        $idSlot = $context->builder->alloca($i32, 'scc_id');
        $next = $context->builder->add(
            $context->builder->load($global),
            $i32->constInt(1, false)
        );
        $context->builder->store($next, $global);
        $context->builder->store($next, $idSlot);
        $id = $context->builder->load($idSlot);

        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $out,
            self::literalKeyString($context, self::MARKER_KEY),
            $context->builder->sext($id, $i64)
        );

        $context->builder->returnValue($out);
        $context->builder->clearInsertionPosition();
    }

    private static function implementGetOptions(Context $context, Value $fn, Value $fnMerge): void
    {
        $entry = $fn->appendBasicBlock('scgo_entry');
        $nullBb = $fn->appendBasicBlock('scgo_null');
        $doneBb = $fn->appendBasicBlock('scgo_done');

        $context->builder->positionAtEnd($entry);

        $src = $fn->getParam(0);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullHt = $htPtr->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $src, $nullHt);
        $context->builder->branchIf($isNull, $nullBb, $doneBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($nullHt);

        $context->builder->positionAtEnd($doneBb);
        $out = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call($fnMerge, $out, $src);
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetStringKey'),
            $out,
            self::literalKeyString($context, self::MARKER_KEY)
        );
        $context->builder->returnValue($out);
        $context->builder->clearInsertionPosition();
    }

    private static function implementSetParams(Context $context, Value $fn, Value $fnMerge): void
    {
        $entry = $fn->appendBasicBlock('scsp_entry');
        $nullBb = $fn->appendBasicBlock('scsp_null');
        $bodyBb = $fn->appendBasicBlock('scsp_body');

        $context->builder->positionAtEnd($entry);

        $dest = $fn->getParam(0);
        $src = $fn->getParam(1);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullHt = $htPtr->constNull();
        $eitherNull = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $dest, $nullHt),
            $context->builder->icmp(Builder::INT_EQ, $src, $nullHt)
        );
        $context->builder->branchIf($eitherNull, $nullBb, $bodyBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $paramsBag = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call($fnMerge, $paramsBag, $src);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $dest,
            self::literalKeyString($context, self::PARAMS_MARKER_KEY),
            $paramsBag
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementMergeOptions(Context $context, Value $fn): void
    {
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('scm_entry');
        $nullBb = $fn->appendBasicBlock('scm_null');
        $loopInit = $fn->appendBasicBlock('scm_init');
        $loopHead = $fn->appendBasicBlock('scm_head');
        $loopBody = $fn->appendBasicBlock('scm_body');
        $loopAdvance = $fn->appendBasicBlock('scm_advance');
        $loopDone = $fn->appendBasicBlock('scm_done');

        $context->builder->positionAtEnd($entry);

        $dest = $fn->getParam(0);
        $src = $fn->getParam(1);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $nullHt = $htPtr->constNull();
        $eitherNull = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $dest, $nullHt),
            $context->builder->icmp(Builder::INT_EQ, $src, $nullHt)
        );
        $context->builder->branchIf($eitherNull, $nullBb, $loopInit);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnVoid();

        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $context->builder->positionAtEnd($loopInit);
        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrType);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($src, $map['strKeys'])),
            $nodeSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($isNull, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        self::mergeScalar($context, $dest, $keyStr, $valField, $fn);
        $context->builder->branch($loopAdvance);

        $context->builder->positionAtEnd($loopAdvance);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $nodeSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function mergeScalar(
        Context $context,
        Value $dest,
        Value $keyStr,
        Value $valField,
        Value $mergeFn
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $doubleTy = $context->getTypeFromString('double');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');

        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($valField, $valueMap['type']));
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $tag = (string) (++self::$blockSerial);
        $parentFn = BasicBlockHelper::parentFunction($context);
        $doneBb = $parentFn->appendBasicBlock('scm_scalar_done_'.$tag);

        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
        $htBb = $parentFn->appendBasicBlock('scm_scalar_ht_'.$tag);
        $scalarBb = $parentFn->appendBasicBlock('scm_scalar_chain_'.$tag);
        $context->builder->branchIf($isHt, $htBb, $scalarBb);

        $context->builder->positionAtEnd($htBb);
        $nested = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valField
        );
        $child = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call($mergeFn, $child, $nested);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $dest,
            $keyStr,
            $child
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($scalarBb);

        $setString = $context->lookupFunction('__hashtable__setStringKeyString');
        $setLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $setBool = $context->lookupFunction('__hashtable__setStringKeyBool');

        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $nullBb = $parentFn->appendBasicBlock('scm_scalar_null_'.$tag);
        $nonNullBb = $parentFn->appendBasicBlock('scm_scalar_nonnull_'.$tag);
        $context->builder->branchIf($isNull, $nullBb, $nonNullBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call(
            $setString,
            $dest,
            $keyStr,
            self::literalValueString($context, '')
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($nonNullBb);

        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $boolBb = $parentFn->appendBasicBlock('scm_scalar_bool_'.$tag);
        $afterBool = $parentFn->appendBasicBlock('scm_scalar_after_bool_'.$tag);
        $context->builder->branchIf($isBool, $boolBb, $afterBool);

        $context->builder->positionAtEnd($boolBb);
        $valueField = $context->builder->structGep($valField, $valueMap['value']);
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $boolByte = $context->builder->load($firstByte);
        $boolVal = $context->builder->icmp(
            Builder::INT_NE,
            $boolByte,
            $i8->constInt(0, false)
        );
        $context->builder->call($setBool, $dest, $keyStr, $boolVal);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($afterBool);

        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $longBb = $parentFn->appendBasicBlock('scm_scalar_long_'.$tag);
        $afterLong = $parentFn->appendBasicBlock('scm_scalar_after_long_'.$tag);
        $context->builder->branchIf($isLong, $longBb, $afterLong);

        $context->builder->positionAtEnd($longBb);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valField
        );
        $context->builder->call($setLong, $dest, $keyStr, $longVal);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($afterLong);

        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $doubleBb = $parentFn->appendBasicBlock('scm_scalar_double_'.$tag);
        $afterDouble = $parentFn->appendBasicBlock('scm_scalar_after_double_'.$tag);
        $context->builder->branchIf($isDouble, $doubleBb, $afterDouble);

        $context->builder->positionAtEnd($doubleBb);
        $dval = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $valField
        );
        $asLong = $context->builder->fpToSi($dval, $i64);
        $context->builder->call($setLong, $dest, $keyStr, $asLong);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($afterDouble);

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );
        $stringBb = $parentFn->appendBasicBlock('scm_scalar_string_'.$tag);
        $stringDone = $parentFn->appendBasicBlock('scm_scalar_string_done_'.$tag);
        $context->builder->branchIf($isString, $stringBb, $doneBb);

        $context->builder->positionAtEnd($stringBb);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valField
        );
        $context->builder->call($setString, $dest, $keyStr, $str);
        $context->builder->branch($stringDone);

        $context->builder->positionAtEnd($stringDone);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function ensureNextIdGlobal(Context $context): void
    {
        $existing = $context->module->getNamedGlobal(self::GLOBAL_NEXT_ID);
        if (null !== $existing) {
            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $g = $context->module->addGlobal($i32, self::GLOBAL_NEXT_ID);
        $g->setInitializer($i32->constInt(0, false));
    }

    public static function ensureDefaultGlobalDeclared(Context $context): void
    {
        $existing = $context->module->getNamedGlobal(self::GLOBAL_DEFAULT);
        if (null !== $existing) {
            return;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $g = $context->module->addGlobal($htPtr, self::GLOBAL_DEFAULT);
        $g->setInitializer($htPtr->constNull());
    }

    private static function literalKeyString(Context $context, string $text): Value
    {
        return self::literalValueString($context, $text);
    }

    private static function literalValueString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $i8p);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $voidTy = $context->getTypeFromString('void');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $doubleTy = $context->getTypeFromString('double');

        foreach (
            [
                ['__hashtable__alloc', $htPtr, []],
                ['__hashtable__setStringKeyLong', $voidTy, [$htPtr, $strPtr, $i64]],
                ['__hashtable__setStringKeyString', $voidTy, [$htPtr, $strPtr, $strPtr]],
                ['__hashtable__setStringKeyBool', $voidTy, [$htPtr, $strPtr, $i1]],
                ['__hashtable__setStringKeyHashtable', $voidTy, [$htPtr, $strPtr, $htPtr]],
                ['__string__init', $strPtr, [$i64, $context->getTypeFromString('int8*')]],
                ['__value__readHashtable', $htPtr, [$valuePtrTy]],
                ['__value__readLong', $i64, [$valuePtrTy]],
                ['__value__readDouble', $doubleTy, [$valuePtrTy]],
                ['__value__readString', $strPtr, [$valuePtrTy]],
                ['__hashtable__unsetStringKey', $voidTy, [$htPtr, $strPtr]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal(
                $context,
                $name,
                $context->context->functionType($ret, false, ...$params)
            );
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (
            [
                '__phpc_stream_context_create',
                '__phpc_stream_context_merge_options',
                '__phpc_stream_context_get_options',
                '__phpc_stream_context_set_params',
            ] as $name
        ) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamContextRuntime LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
