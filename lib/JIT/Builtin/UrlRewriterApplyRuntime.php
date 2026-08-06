<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * ABI `__phpc_url_rewriter_apply` — LLVM href query rewrite (#27566).
 *
 * NestedJIT UrlScannerEx / substr / str_replace miscompile under thin AOT.
 * Build {@see OutputRewriteVarsStorage} url_app at emitAdd time; apply injects
 * `?url_app` / `&url_app` into `href="…"` attribute values (php-src
 * ext/standard/url_scanner_ex.re — subset sufficient for a=href).
 */
final class UrlRewriterApplyRuntime
{
    private const ABI = '__phpc_url_rewriter_apply';

    /** Declare ABI only (no body) — safe during ObOutput NestedJIT. */
    public static function declareAbi(Context $context): void
    {
        $existing = $context->module->getNamedFunction(self::ABI);
        if (null !== $existing) {
            $context->registerFunction(self::ABI, $existing);

            return;
        }
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = $context->module->addFunction(self::ABI, $ft);
        $context->registerFunction(self::ABI, $fn);
    }

    public static function emitApplyCall(Context $context, Value $contentStr): Value
    {
        self::declareAbi($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $contentStr);
    }

    /** Implement ABI body with libc scan (no NestedJIT). */
    public static function ensureLinked(Context $context): void
    {
        self::declareAbi($context);
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }
        $fn = $context->lookupFunction(self::ABI);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }
        $restore = null;
        try {
            $restore = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        OutputRewriteVarsStorage::ensureGlobals($context);
        OutputRewriteVarsStorage::ensureLibc($context);
        self::ensureLibc($context);
        self::ensureStringInit($context);

        $entry = $fn->appendBasicBlock('url_rewriter_apply_entry');
        $context->builder->positionAtEnd($entry);
        self::emitLlvmHrefApplyBody($context, $fn);

        if (null !== $restore) {
            $context->builder->positionAtEnd($restore);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** @deprecated use ensureLinked — kept for emitAdd call sites */
    public static function emitInstallHook(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function emitLlvmHrefApplyBody(Context $context, LlvmFunction $fn): void
    {
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');

        $content = $fn->getParam(0);
        $contentLen = $context->builder->load($context->builder->structGep($content, $map['length']));
        $contentPtr = $context->builder->pointerCast($context->builder->structGep($content, $map['value']), $i8p);

        $appLen = $context->builder->load(
            OutputRewriteVarsStorage::lenPtrPublic($context, OutputRewriteVarsStorage::GLOBAL_URL_APP_LEN)
        );
        $appRow = OutputRewriteVarsStorage::bufPtrPublic($context, OutputRewriteVarsStorage::GLOBAL_URL_APP);

        $emptyApp = $context->builder->icmp(Builder::INT_EQ, $appLen, $i64->constInt(0, false));
        $passthrough = $fn->appendBasicBlock('ura_passthrough');
        $work = $fn->appendBasicBlock('ura_work');
        $context->builder->branchIf($emptyApp, $passthrough, $work);

        $context->builder->positionAtEnd($passthrough);
        $context->builder->returnValue($content);

        $context->builder->positionAtEnd($work);
        // Budget: content + up to 8 query injections.
        $extra = $context->builder->mul($appLen, $i64->constInt(8, false));
        $budget = $context->builder->add(
            $context->builder->add($contentLen, $extra),
            $i64->constInt(16, false)
        );
        $outBuf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->trunc($budget, $sizeT)
        );
        $outBase = $context->builder->pointerCast($outBuf, $i8p);
        $needle = $context->builder->pointerCast($context->constantFromString('href="'), $i8p);

        // Locals via alloca
        $posAlloca = $context->builder->alloca($i8p, 1, 'ura_pos');
        $outPosAlloca = $context->builder->alloca($i64, 1, 'ura_outpos');
        $context->builder->store($contentPtr, $posAlloca);
        $context->builder->store($i64->constInt(0, false), $outPosAlloca);

        $loop = $fn->appendBasicBlock('ura_loop');
        $found = $fn->appendBasicBlock('ura_found');
        $finish = $fn->appendBasicBlock('ura_finish');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $pos = $context->builder->load($posAlloca);
        $hit = $context->builder->call($context->lookupFunction('strstr'), $pos, $needle);
        $miss = $context->builder->icmp(Builder::INT_EQ, $hit, $i8p->constNull());
        $context->builder->branchIf($miss, $finish, $found);

        $context->builder->positionAtEnd($found);
        // Copy bytes from pos through end of href=" (6 chars).
        $toNeedle = $context->builder->sub(
            $context->builder->ptrToInt($hit, $i64),
            $context->builder->ptrToInt($pos, $i64)
        );
        $chunkLen = $context->builder->add($toNeedle, $i64->constInt(6, false));
        $outPos = $context->builder->load($outPosAlloca);
        $dest = $context->builder->inBoundsGEP($outBase, $outPos);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $dest,
            $pos,
            $context->builder->trunc($chunkLen, $sizeT)
        );
        $outPos2 = $context->builder->add($outPos, $chunkLen);
        $urlStart = $context->builder->inBoundsGEP($hit, $i64->constInt(6, false));
        $i32 = $context->getTypeFromString('int32');
        $quote = $context->builder->call(
            $context->lookupFunction('strchr'),
            $urlStart,
            $i32->constInt(\ord('"'), false)
        );
        $noQuote = $context->builder->icmp(Builder::INT_EQ, $quote, $i8p->constNull());
        $badQuote = $fn->appendBasicBlock('ura_bad_quote');
        $goodQuote = $fn->appendBasicBlock('ura_good_quote');
        $context->builder->branchIf($noQuote, $badQuote, $goodQuote);

        $context->builder->positionAtEnd($badQuote);
        // Copy remaining from urlStart and finish.
        $context->builder->store($urlStart, $posAlloca);
        $context->builder->store($outPos2, $outPosAlloca);
        $context->builder->branch($finish);

        $context->builder->positionAtEnd($goodQuote);
        $urlLen = $context->builder->sub(
            $context->builder->ptrToInt($quote, $i64),
            $context->builder->ptrToInt($urlStart, $i64)
        );
        $destUrl = $context->builder->inBoundsGEP($outBase, $outPos2);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $destUrl,
            $urlStart,
            $context->builder->trunc($urlLen, $sizeT)
        );
        $outPos3 = $context->builder->add($outPos2, $urlLen);

        // sep = '?' if url has no '?', else '&'
        $qmark = $context->builder->call(
            $context->lookupFunction('memchr'),
            $urlStart,
            $i32->constInt(\ord('?'), false),
            $context->builder->trunc($urlLen, $sizeT)
        );
        $hasQ = $context->builder->icmp(Builder::INT_NE, $qmark, $i8p->constNull());
        $sep = $context->builder->select(
            $hasQ,
            $i8->constInt(\ord('&'), false),
            $i8->constInt(\ord('?'), false)
        );
        $sepDest = $context->builder->inBoundsGEP($outBase, $outPos3);
        $context->builder->store($sep, $sepDest);
        $outPos4 = $context->builder->add($outPos3, $i64->constInt(1, false));
        $appDest = $context->builder->inBoundsGEP($outBase, $outPos4);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $appDest,
            $appRow,
            $context->builder->trunc($appLen, $sizeT)
        );
        $outPos5 = $context->builder->add($outPos4, $appLen);
        // closing quote
        $qd = $context->builder->inBoundsGEP($outBase, $outPos5);
        $context->builder->store($i8->constInt(\ord('"'), false), $qd);
        $outPos6 = $context->builder->add($outPos5, $i64->constInt(1, false));
        $context->builder->store($outPos6, $outPosAlloca);
        // continue after quote
        $context->builder->store(
            $context->builder->inBoundsGEP($quote, $i64->constInt(1, false)),
            $posAlloca
        );
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($finish);
        $posF = $context->builder->load($posAlloca);
        $restLen = $context->builder->call($context->lookupFunction('strlen'), $posF);
        $outPosF = $context->builder->load($outPosAlloca);
        $restDest = $context->builder->inBoundsGEP($outBase, $outPosF);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $restDest,
            $posF,
            $restLen
        );
        $finalLen = $context->builder->add($outPosF, $context->builder->zExt($restLen, $i64));
        $term = $context->builder->inBoundsGEP($outBase, $finalLen);
        $context->builder->store($i8->constInt(0, false), $term);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $finalLen,
            $outBase
        );
        $context->builder->returnValue($result);
    }

    private static function ensureLibc(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        self::ensureExternal($context, 'strstr', $context->context->functionType($i8p, false, $i8p, $i8p));
        self::ensureExternal($context, 'strchr', $context->context->functionType($i8p, false, $i8p, $i32));
        self::ensureExternal($context, 'strlen', $context->context->functionType($sizeT, false, $i8p));
        self::ensureExternal(
            $context,
            'memchr',
            $context->context->functionType($i8p, false, $i8p, $i32, $sizeT)
        );
    }

    private static function ensureStringInit(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType($strPtr, false, $i64, $i8p)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }
}
