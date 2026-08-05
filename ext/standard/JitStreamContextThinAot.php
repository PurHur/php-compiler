<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
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
        self::stampMarker($context, $out);

        $params = $fn->getParam(1);
        $nullHt = $htPtr->constNull();
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
        $context->builder->positionAtEnd($entry);
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
        $context->builder->positionAtEnd($entry);
        $out = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
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
        $context->builder->positionAtEnd($entry);
        $context->builder->returnVoid();
        $context->registerFunction('__phpc_stream_context_set_single_option', $fn);
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
        $i64 = $context->getTypeFromString('int64');
        $void = $context->getTypeFromString('void');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyLong', $void, [$htPtr, $strPtr, $i64]],
            ['__hashtable__setStringKeyHashtable', $void, [$htPtr, $strPtr, $htPtr]],
            ['__hashtable__readStringKeyHashtable', $htPtr, [$htPtr, $strPtr]],
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
