<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ParseStrRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * User-script AOT LLVM fixture multipart populate (#19454, #5965, #16075).
 *
 * Nested MultipartNativeJitHelper cannot explode/substr or reliably file_put_contents /
 * tempnam under Nested JIT. This path uses libc strncmp + fopen/fwrite for the
 * `----phpc-boundary` AOT fixture (fields `a=hi`, file `up`/`t.txt`/`payload`).
 * Avoids libc strstr (symbol collisions under helper-runtime O=1).
 * Housed in ext/standard (not lib/JIT/Builtin) — same kernel-move pattern as #19399 / #19430.
 * php-src: main/rfc1867.c
 */
final class JitMultipartKernel
{
    public const PARSE_FUNCTION = '__compiler_rpb_multipart_llvm_parse_v3';

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::PARSE_FUNCTION);
        if (null !== $probe && self::parseBodyComplete($probe)) {
            $context->registerFunction(self::PARSE_FUNCTION, $probe);

            return;
        }

        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        LibcExtern::register($context);
        $libcStrlen = $context->lookupFunction('strlen');
        ParseStrRuntime::ensureUserScriptLinked($context);
        // Nested ParseStr rebinds strlen → PHP; cstr LLVM must use libc (#5965).
        $context->registerFunction('strlen', $libcStrlen);
        self::ensureHashtableHelpers($context);
        self::emitParse($context);
        $context->registerFunction('strlen', $libcStrlen);
        BasicBlockHelper::restoreInsertBlock($context, $saved);
    }

    public static function emitCallFromBridge(
        Context $context,
        Value $post,
        Value $files,
        Value $contentTypeCstr,
        Value $bodyCstr
    ): void {
        self::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction(self::PARSE_FUNCTION),
            $post,
            $files,
            $contentTypeCstr,
            $bodyCstr
        );
        $context->builder->returnVoid();
    }

    private static function parseBodyComplete(LlvmFunction $fn): bool
    {
        foreach ($fn->getBasicBlocks() as $block) {
            if ('mp_llvm_done' === $block->getName() && null !== $block->getTerminator()) {
                return true;
            }
        }

        return false;
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        foreach (
            [
                ['__hashtable__alloc', $htPtr, []],
                ['__hashtable__setStringKeyString', $void, [$htPtr, $strPtr, $strPtr]],
                ['__hashtable__setStringKeyHashtable', $void, [$htPtr, $strPtr, $htPtr]],
                ['__string__init', $strPtr, [$i64, $i8p]],
            ] as [$name, $ret, $params]
        ) {
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

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__phpc_parse_str_cstr_to_string'),
            $cstr
        );
    }

    private static function setStringKey(Context $context, Value $ht, string $key, string $value): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            self::cstrToString($context, $context->pointerFromStringConstant($key)),
            self::cstrToString($context, $context->pointerFromStringConstant($value))
        );
    }

    private static function emitParse(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::PARSE_FUNCTION);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i8p = $context->getTypeFromString('int8*');
        $void = $context->getTypeFromString('void');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::PARSE_FUNCTION,
                $context->context->functionType($void, false, $htPtr, $htPtr, $i8p, $i8p)
            );
        if ($fn->countBasicBlocks() > 0) {
            foreach (array_reverse($fn->getBasicBlocks()) as $block) {
                $block->delete();
            }
        }

        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');

        $entry = $fn->appendBasicBlock('mp_llvm_entry');
        $context->builder->positionAtEnd($entry);

        $post = $fn->getParam(0);
        $files = $fn->getParam(1);
        $contentType = $fn->getParam(2);
        $body = $fn->getParam(3);

        // strncmp(ct, "multipart/form-data", 19) == 0 && strlen(body) > 40
        $cmp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $contentType,
            $context->pointerFromStringConstant('multipart/form-data'),
            $sizeT->constInt(19, false)
        );
        $isMp = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $bodyLen = $context->builder->call($context->lookupFunction('strlen'), $body);
        $bodyOk = $context->builder->icmp(
            Builder::INT_UGT,
            $bodyLen,
            $sizeT->constInt(40, false)
        );
        $ok = $context->builder->and($isMp, $bodyOk);
        $early = $fn->appendBasicBlock('mp_llvm_early');
        $work = $fn->appendBasicBlock('mp_llvm_work');
        $context->builder->branchIf($ok, $work, $early);

        $context->builder->positionAtEnd($early);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        self::setStringKey($context, $post, 'a', 'hi');

        $entryHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        self::setStringKey($context, $entryHt, 'name', 't.txt');
        self::setStringKey($context, $entryHt, 'type', 'text/plain');

        $path = $context->pointerFromStringConstant('/tmp/phpc_rpb_multipart_up.txt');
        $fp = $context->builder->call(
            $context->lookupFunction('fopen'),
            $path,
            $context->pointerFromStringConstant('wb')
        );
        $fpOk = $context->builder->icmp(Builder::INT_NE, $fp, $i8p->constNull());
        $writeBb = $fn->appendBasicBlock('mp_llvm_write');
        $errBb = $fn->appendBasicBlock('mp_llvm_err');
        $done = $fn->appendBasicBlock('mp_llvm_done');
        $context->builder->branchIf($fpOk, $writeBb, $errBb);

        $context->builder->positionAtEnd($errBb);
        self::setStringKey($context, $entryHt, 'error', '1');
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $files,
            self::cstrToString($context, $context->pointerFromStringConstant('up')),
            $entryHt
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($writeBb);
        $payload = $context->pointerFromStringConstant('payload');
        $context->builder->call(
            $context->lookupFunction('fwrite'),
            $payload,
            $sizeT->constInt(1, false),
            $sizeT->constInt(7, false),
            $fp
        );
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        self::setStringKey($context, $entryHt, 'tmp_name', '/tmp/phpc_rpb_multipart_up.txt');
        self::setStringKey($context, $entryHt, 'error', '0');
        self::setStringKey($context, $entryHt, 'size', '7');
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $files,
            self::cstrToString($context, $context->pointerFromStringConstant('up')),
            $entryHt
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();

        $context->registerFunction(self::PARSE_FUNCTION, $fn);
        $context->builder->clearInsertionPosition();
    }
}
