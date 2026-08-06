<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\HashTableDuplicateRuntime;
use PHPCompiler\JIT\Builtin\StreamGlobalsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableMergeLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Thin user-script AOT stream-context ABI (#27573).
 *
 * NestedJIT of {@see StreamContextJitHelper} fails LLVM verify under thin standalone.
 * Build create/set_params/get_params in LLVM from hashtable markers — peer {@see JitStreamMetaThinAot}.
 *
 * php-src: ext/standard/streamsfuncs.c — stream_context_* / parse_context_params
 */
final class JitStreamContextThinAot
{
    private static int $nextId = 0;

    public static function implement(Context $context): void
    {
        self::ensureExternals($context);
        // HashTable duplicate / resource-like HT paths may ref `__compiler_is_resource` (#27295).
        if ($context->isThinStandaloneAotMain()) {
            StreamGlobalsJit::implementThinIsResource($context);
        }
        self::implementCreate($context);
        self::implementMergeOptions($context);
        self::implementGetOptions($context);
        self::implementSetParams($context);
        self::implementSetSingleOption($context);
        self::implementGetParams($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementCreate(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = self::declareAbi(
            $context,
            '__phpc_stream_context_create',
            $context->context->functionType($htPtr, false, $htPtr, $htPtr)
        );
        $entry = $fn->appendBasicBlock('sctx_thin_create');
        $context->builder->positionAtEnd($entry);

        $out = $context->builder->call($context->lookupFunction('__hashtable__alloc'));

        $options = $fn->getParam(0);
        $nullHt = $htPtr->constNull();
        $hasOptions = $context->builder->icmp(Builder::INT_NE, $options, $nullHt);
        $optsBb = $fn->appendBasicBlock('sctx_thin_create_opts');
        $afterOpts = $fn->appendBasicBlock('sctx_thin_create_after_opts');
        $context->builder->branchIf($hasOptions, $optsBb, $afterOpts);

        $context->builder->positionAtEnd($optsBb);
        HashTableMergeLlvm::mergeArrayInto($context, $out, $options);
        $context->builder->branch($afterOpts);

        $context->builder->positionAtEnd($afterOpts);
        self::stampMarker($context, $out);

        $params = $fn->getParam(1);
        $hasParams = $context->builder->icmp(Builder::INT_NE, $params, $nullHt);
        $paramsBb = $fn->appendBasicBlock('sctx_thin_create_params');
        $retBb = $fn->appendBasicBlock('sctx_thin_create_ret');
        $context->builder->branchIf($hasParams, $paramsBb, $retBb);

        $context->builder->positionAtEnd($paramsBb);
        self::storeParamsBag($context, $out, $params);
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnValue($out);
        $context->registerFunction('__phpc_stream_context_create', $fn);
    }

    private static function implementMergeOptions(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = self::declareAbi(
            $context,
            '__phpc_stream_context_merge_options',
            $context->context->functionType($context->getTypeFromString('void'), false, $htPtr, $htPtr)
        );
        $entry = $fn->appendBasicBlock('sctx_thin_merge');
        $fail = $fn->appendBasicBlock('sctx_thin_merge_fail');
        $body = $fn->appendBasicBlock('sctx_thin_merge_body');
        $context->builder->positionAtEnd($entry);

        $dest = $fn->getParam(0);
        $src = $fn->getParam(1);
        $nullHt = $htPtr->constNull();
        $destOk = $context->builder->icmp(Builder::INT_NE, $dest, $nullHt);
        $srcOk = $context->builder->icmp(Builder::INT_NE, $src, $nullHt);
        $bothOk = $context->builder->and($destOk, $srcOk);
        $context->builder->branchIf($bothOk, $body, $fail);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($body);
        HashTableMergeLlvm::mergeArrayInto($context, $dest, $src);
        $context->builder->returnVoid();
        $context->registerFunction('__phpc_stream_context_merge_options', $fn);
    }

    private static function implementGetOptions(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = self::declareAbi(
            $context,
            '__phpc_stream_context_get_options',
            $context->context->functionType($htPtr, false, $htPtr)
        );
        $entry = $fn->appendBasicBlock('sctx_thin_getopts');
        $missing = $fn->appendBasicBlock('sctx_thin_getopts_miss');
        $body = $fn->appendBasicBlock('sctx_thin_getopts_body');
        $context->builder->positionAtEnd($entry);

        $src = $fn->getParam(0);
        $nullHt = $htPtr->constNull();
        $srcNull = $context->builder->icmp(Builder::INT_EQ, $src, $nullHt);
        $context->builder->branchIf($srcNull, $missing, $body);

        $context->builder->positionAtEnd($missing);
        $context->builder->returnValue($context->builder->call($context->lookupFunction('__hashtable__alloc')));

        // Duplicate then strip marker/params keys — peer StreamContextJitHelper::getOptions (#27295).
        $context->builder->positionAtEnd($body);
        HashTableDuplicateRuntime::ensureLinked($context);
        $out = HashTableDuplicateRuntime::duplicate($context, $src);
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetStringKey'),
            $out,
            self::literalString($context, VmStreamContext::MARKER_KEY)
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__unsetStringKey'),
            $out,
            self::literalString($context, VmStreamContext::PARAMS_MARKER_KEY)
        );
        $context->builder->returnValue($out);
        $context->registerFunction('__phpc_stream_context_get_options', $fn);
    }

    private static function implementSetParams(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = self::declareAbi(
            $context,
            '__phpc_stream_context_set_params',
            $context->context->functionType($context->getTypeFromString('void'), false, $htPtr, $htPtr)
        );
        $entry = $fn->appendBasicBlock('sctx_thin_setparams');
        $fail = $fn->appendBasicBlock('sctx_thin_setparams_fail');
        $body = $fn->appendBasicBlock('sctx_thin_setparams_body');
        $context->builder->positionAtEnd($entry);

        $dest = $fn->getParam(0);
        $params = $fn->getParam(1);
        $nullHt = $htPtr->constNull();
        $destOk = $context->builder->icmp(Builder::INT_NE, $dest, $nullHt);
        $context->builder->branchIf($destOk, $body, $fail);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($body);
        // Inline `[]` / missing params may arrive as null HT — allocate empty bag (#27573).
        $paramsNull = $context->builder->icmp(Builder::INT_EQ, $params, $nullHt);
        $emptyParams = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $paramsUse = $context->builder->select($paramsNull, $emptyParams, $params);
        self::storeParamsBag($context, $dest, $paramsUse);
        $context->builder->returnVoid();
        $context->registerFunction('__phpc_stream_context_set_params', $fn);
    }

    private static function implementSetSingleOption(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $fn = self::declareAbi(
            $context,
            '__phpc_stream_context_set_single_option',
            $context->context->functionType(
                $context->getTypeFromString('void'),
                false,
                $htPtr,
                $valPtr,
                $valPtr,
                $valPtr
            )
        );
        $entry = $fn->appendBasicBlock('sctx_thin_setopt');
        $fail = $fn->appendBasicBlock('sctx_thin_setopt_fail');
        $body = $fn->appendBasicBlock('sctx_thin_setopt_body');
        $haveWrapper = $fn->appendBasicBlock('sctx_thin_setopt_have_wrapper');
        $newWrapper = $fn->appendBasicBlock('sctx_thin_setopt_new_wrapper');
        $store = $fn->appendBasicBlock('sctx_thin_setopt_store');
        $context->builder->positionAtEnd($entry);

        $dest = $fn->getParam(0);
        $nullHt = $htPtr->constNull();
        $destOk = $context->builder->icmp(Builder::INT_NE, $dest, $nullHt);
        $context->builder->branchIf($destOk, $body, $fail);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnVoid();

        // Singular set_option($ctx, $wrapper, $option, $value) — store under wrapper HT (#27295).
        $context->builder->positionAtEnd($body);
        $wrapperStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $fn->getParam(1)
        );
        $optionStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $fn->getParam(2)
        );
        $existing = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyHashtable'),
            $dest,
            $wrapperStr
        );
        $existingNull = $context->builder->icmp(Builder::INT_EQ, $existing, $nullHt);
        $context->builder->branchIf($existingNull, $newWrapper, $haveWrapper);

        $context->builder->positionAtEnd($newWrapper);
        $allocated = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $dest,
            $wrapperStr,
            $allocated
        );
        $context->builder->branch($store);

        $context->builder->positionAtEnd($haveWrapper);
        $context->builder->branch($store);

        $context->builder->positionAtEnd($store);
        $wrapperHt = $context->builder->phi($htPtr);
        $wrapperHt->addIncoming($allocated, $newWrapper);
        $wrapperHt->addIncoming($existing, $haveWrapper);
        self::storeBoxedValueAtStringKey($context, $fn, $wrapperHt, $optionStr, $fn->getParam(3));
        $context->builder->returnVoid();
        $context->registerFunction('__phpc_stream_context_set_single_option', $fn);
    }

    /**
     * Write a boxed {@see __value__*} under a string key (string / long / bool / null common path).
     */
    private static function storeBoxedValueAtStringKey(
        Context $context,
        LlvmFunction $fn,
        Value $ht,
        Value $keyStr,
        Value $valuePtr
    ): void {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $stringBb = $fn->appendBasicBlock('sctx_thin_store_string');
        $longBb = $fn->appendBasicBlock('sctx_thin_store_long');
        $boolBb = $fn->appendBasicBlock('sctx_thin_store_bool');
        $nullBb = $fn->appendBasicBlock('sctx_thin_store_null');
        $doneBb = $fn->appendBasicBlock('sctx_thin_store_done');
        $checkLong = $fn->appendBasicBlock('sctx_thin_store_check_long');
        $checkBool = $fn->appendBasicBlock('sctx_thin_store_check_bool');
        $checkNull = $fn->appendBasicBlock('sctx_thin_store_check_null');

        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBb, $checkLong);

        $context->builder->positionAtEnd($checkLong);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
        );
        $context->builder->branchIf($isLong, $longBb, $checkBool);

        $context->builder->positionAtEnd($checkBool);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)
        );
        $context->builder->branchIf($isBool, $boolBb, $checkNull);

        $context->builder->positionAtEnd($checkNull);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NULL, false)
        );
        $context->builder->branchIf($isNull, $nullBb, $doneBb);

        $context->builder->positionAtEnd($stringBb);
        $str = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            $owned
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($longBb);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $keyStr,
            $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($boolBb);
        $valueField = $context->builder->structGep($valuePtr, $map['value']);
        $boolByte = $context->builder->load(
            $context->builder->inBoundsGEP(
                $valueField,
                $context->getTypeFromString('int32')->constInt(0, false),
                $context->getTypeFromString('int64')->constInt(0, false)
            )
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyBool'),
            $ht,
            $keyStr,
            $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false))
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyNull'),
            $ht,
            $keyStr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function implementGetParams(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $fn = self::declareAbi(
            $context,
            '__phpc_stream_context_get_params',
            $context->context->functionType($htPtr, false, $htPtr)
        );
        $entry = $fn->appendBasicBlock('sctx_thin_getparams');
        $missing = $fn->appendBasicBlock('sctx_thin_getparams_miss');
        $checkBag = $fn->appendBasicBlock('sctx_thin_getparams_check');
        $context->builder->positionAtEnd($entry);

        $src = $fn->getParam(0);
        $nullHt = $htPtr->constNull();
        $srcNull = $context->builder->icmp(Builder::INT_EQ, $src, $nullHt);
        $context->builder->branchIf($srcNull, $missing, $checkBag);

        $context->builder->positionAtEnd($missing);
        $context->builder->returnValue(self::emptyParamsResult($context));

        $context->builder->positionAtEnd($checkBag);
        $bag = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyHashtable'),
            $src,
            self::literalString($context, VmStreamContext::PARAMS_MARKER_KEY)
        );
        $bagNull = $context->builder->icmp(Builder::INT_EQ, $bag, $nullHt);
        $noBag = $fn->appendBasicBlock('sctx_thin_getparams_nobag');
        $haveBag = $fn->appendBasicBlock('sctx_thin_getparams_bag');
        $context->builder->branchIf($bagNull, $noBag, $haveBag);

        $context->builder->positionAtEnd($noBag);
        $context->builder->returnValue(self::emptyParamsResult($context));

        // Params bag already holds notification (+ other keys). Return it so
        // array_key_exists('notification') matches Zend (#27573 / #19696).
        $context->builder->positionAtEnd($haveBag);
        $context->builder->returnValue($bag);
        $context->registerFunction('__phpc_stream_context_get_params', $fn);
    }

    private static function emptyParamsResult(Context $context): Value
    {
        $out = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $opts = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $out,
            self::literalString($context, 'options'),
            $opts
        );

        return $out;
    }

    private static function storeParamsBag(Context $context, Value $dest, Value $params): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $dest,
            self::literalString($context, VmStreamContext::PARAMS_MARKER_KEY),
            $params
        );
    }

    private static function stampMarker(Context $context, Value $ht): void
    {
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            self::literalString($context, VmStreamContext::MARKER_KEY),
            $i64->constInt(++self::$nextId, false)
        );
    }

    private static function literalString(Context $context, string $text): Value
    {
        return $context->builder->load($context->constantStringFromString($text));
    }

    private static function declareAbi(Context $context, string $name, $ft): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe) {
            if ($probe->countBasicBlocks() > 0) {
                foreach (\array_reverse($probe->getBasicBlocks()) as $block) {
                    $block->delete();
                }
            }

            return $probe;
        }

        return $context->module->addFunction($name, $ft);
    }

    private static function ensureExternals(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valPtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $void = $context->getTypeFromString('void');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyLong', $void, [$htPtr, $strPtr, $i64]],
            ['__hashtable__setStringKeyBool', $void, [$htPtr, $strPtr, $i1]],
            ['__hashtable__setStringKeyNull', $void, [$htPtr, $strPtr]],
            ['__hashtable__setStringKeyString', $void, [$htPtr, $strPtr, $strPtr]],
            ['__hashtable__setStringKeyHashtable', $void, [$htPtr, $strPtr, $htPtr]],
            ['__hashtable__readStringKeyHashtable', $htPtr, [$htPtr, $strPtr]],
            ['__hashtable__unsetStringKey', $void, [$htPtr, $strPtr]],
            ['__value__readString', $strPtr, [$valPtr]],
            ['__value__readLong', $i64, [$valPtr]],
            ['__string__separate', $strPtr, [$strPtr]],
        ] as [$name, $ret, $params]) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }
}
