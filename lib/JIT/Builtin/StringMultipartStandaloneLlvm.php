<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Standalone AOT LLVM multipart POST quarantine (#7302, #9394).
 *
 * Default standalone uses {@see \PHPCompiler\Web\MultipartParser} via {@see SuperglobalRefreshRuntime}.
 * Opt-in LLVM superglobal refresh links these bodies from {@see SuperglobalRefreshStandaloneLlvm}.
 * php-src: main/rfc1867.c, main/php_variables.c
 */
final class StringMultipartStandaloneLlvm
{
    private const MAX_BODY = 8 * 1024 * 1024;

    private const BOUNDARY_CAP = 256;

    private const DELIM_CAP = 260;

    private const FIELD_CAP = 256;

    private const PAIR_CAP = 4096;

    private const NEEDLE_CAP = 64;

    /** Standalone AOT: multipart POST helper for superglobals_refresh.c (#7302). */
    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        self::ensureLibc($context);
        self::ensureRuntimeHelpers($context);

        foreach (
            [
                '__phpc_multipart_cstr_to_string',
                '__phpc_multipart_set_string_key',
                '__phpc_multipart_extract_boundary',
                '__phpc_multipart_find_header_value',
                '__phpc_multipart_param',
                '__phpc_multipart_set_file_entry',
                '__phpc_multipart_normalize_body_newlines',
                '__phpc_parse_multipart_post',
            ] as $name
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = self::declareFunction($context, $name);
                $context->registerFunction($name, $fn);
            }
        }

        self::implementIfMissing($context, '__phpc_multipart_cstr_to_string', self::emitCstrToString(...));
        self::implementIfMissing($context, '__phpc_multipart_set_string_key', self::emitSetStringKey(...));
        self::implementIfMissing($context, '__phpc_multipart_extract_boundary', self::emitExtractBoundary(...));
        self::implementIfMissing($context, '__phpc_multipart_find_header_value', self::emitFindHeaderValue(...));
        self::implementIfMissing($context, '__phpc_multipart_param', self::emitMultipartParam(...));
        self::implementIfMissing($context, '__phpc_multipart_set_file_entry', self::emitSetFileEntry(...));
        self::implementIfMissing($context, '__phpc_multipart_normalize_body_newlines', self::emitNormalizeBodyNewlines(...));
        self::implementIfMissing($context, '__phpc_parse_multipart_post', self::emitParseMultipartPost(...));

        self::restoreInsertBlock($context, $restore);
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        try {
            $fn = $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = self::declareFunction($context, $name);
            $context->registerFunction($name, $fn);
        }

        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $sizeTp = $sizeT->pointerType(0);

        return match ($name) {
            '__phpc_multipart_cstr_to_string' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i8p)
            ),
            '__phpc_multipart_set_string_key' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $htPtr, $i8p, $i8p)
            ),
            '__phpc_multipart_extract_boundary' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8p, $i8p, $sizeT)
            ),
            '__phpc_multipart_find_header_value' => $context->module->addFunction(
                $name,
                $context->context->functionType($i8p, false, $i8p, $i8p)
            ),
            '__phpc_multipart_param' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8p, $i8p, $i8p, $sizeT)
            ),
            '__phpc_multipart_set_file_entry' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $htPtr, $i8p, $i8p, $i8p, $i8p, $sizeT)
            ),
            '__phpc_multipart_normalize_body_newlines' => $context->module->addFunction(
                $name,
                $context->context->functionType($i8p, false, $i8p, $sizeTp)
            ),
            '__phpc_parse_multipart_post' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $htPtr, $htPtr, $i8p, $i8p)
            ),
            default => throw new \LogicException('Unknown multipart JIT helper: '.$name),
        };
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['malloc', $voidPtr, [$sizeT]],
                ['free', $voidTy, [$i8p]],
                ['memcpy', $voidPtr, [$voidPtr, $voidPtr, $sizeT]],
                ['strlen', $sizeT, [$i8p]],
                ['strchr', $i8p, [$i8p, $i32]],
                ['strstr', $i8p, [$i8p, $i8p]],
                ['strncasecmp', $i32, [$i8p, $i8p, $sizeT]],
                ['getenv', $i8p, [$i8p]],
                ['mkstemp', $i32, [$i8p]],
                ['fdopen', $i8p, [$i32, $i8p]],
                ['fwrite', $sizeT, [$voidPtr, $sizeT, $sizeT, $i8p]],
                ['fclose', $i32, [$i8p]],
                ['unlink', $i32, [$i8p]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
        self::ensureExternal(
            $context,
            'snprintf',
            $context->context->functionType($i32, true, $i8p, $sizeT, $i8p)
        );
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->getTypeFromString('void');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['__hashtable__setStringKeyString', $void, [$htPtr, $strPtr, $strPtr]],
                ['__string__init', $strPtr, [$context->getTypeFromString('int64'), $i8p]],
                ['__phpc_parse_str_ensure_child', $htPtr, [$htPtr, $i8p]],
                ['__phpc_parse_str_parse_delimited_pairs', $void, [$htPtr, $i8p, $i8, $i32]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $fnType): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $fnType);
            $context->registerFunction($name, $fn);
        }
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        return $context->pointerFromStringConstant($text);
    }

    private static function emitCstrToString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $cstr = $fn->getParam(0);
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $ret = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $cstr
        );
        $context->builder->returnValue($ret);
    }

    private static function emitSetStringKey(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $value = $fn->getParam(2);
        $keyStr = $context->builder->call($context->lookupFunction('__phpc_multipart_cstr_to_string'), $key);
        $valStr = $context->builder->call($context->lookupFunction('__phpc_multipart_cstr_to_string'), $value);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            $valStr
        );
        $context->builder->returnVoid();
    }

    private static function emitExtractBoundary(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $nullPtr = $i8p->constNull();
        $zeroI8 = $i8->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);

        $contentTypeIn = $fn->getParam(0);
        $out = $fn->getParam(1);
        $outLen = $fn->getParam(2);

        $contentTypeSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $pSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $startSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $lenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);

        $context->builder->store($zeroI8, $out);

        $fail = $fn->appendBasicBlock('fail');
        $useEnv = $fn->appendBasicBlock('use_env');
        $haveType = $fn->appendBasicBlock('have_type');
        $findBoundary = $fn->appendBasicBlock('find_boundary');
        $skipWs = $fn->appendBasicBlock('skip_ws');
        $quoted = $fn->appendBasicBlock('quoted');
        $unquoted = $fn->appendBasicBlock('unquoted');
        $copyOk = $fn->appendBasicBlock('copy_ok');
        $done = $fn->appendBasicBlock('done');

        $context->builder->store($contentTypeIn, $contentTypeSlot);

        $raw = $context->builder->call(
            $context->lookupFunction('getenv'),
            self::literalCstr($context, 'CONTENT_TYPE')
        );
        $rawNull = $context->builder->icmp(Builder::INT_EQ, $raw, $nullPtr);
        $rawEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($raw), $zeroI8);
        $tryHttp = $fn->appendBasicBlock('try_http_ct');
        $context->builder->branchIf($context->builder->or($rawNull, $rawEmpty), $tryHttp, $useEnv);

        $context->builder->positionAtEnd($tryHttp);
        $raw2 = $context->builder->call(
            $context->lookupFunction('getenv'),
            self::literalCstr($context, 'HTTP_CONTENT_TYPE')
        );
        $raw2Null = $context->builder->icmp(Builder::INT_EQ, $raw2, $nullPtr);
        $raw2Empty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($raw2), $zeroI8);
        $afterEnv = $fn->appendBasicBlock('after_env');
        $context->builder->branchIf($context->builder->or($raw2Null, $raw2Empty), $afterEnv, $useEnv);

        $context->builder->positionAtEnd($useEnv);
        $envVal = $context->builder->phi($i8p, 'env_ct');
        $envVal->addIncoming($raw, $entry);
        $envVal->addIncoming($raw2, $tryHttp);
        $context->builder->store($envVal, $contentTypeSlot);
        $context->builder->branch($afterEnv);

        $context->builder->positionAtEnd($afterEnv);
        $contentType = $context->builder->load($contentTypeSlot);
        $typeNull = $context->builder->icmp(Builder::INT_EQ, $contentType, $nullPtr);
        $context->builder->branchIf($typeNull, $fail, $haveType);

        $context->builder->positionAtEnd($haveType);
        $p = $context->builder->call(
            $context->lookupFunction('strstr'),
            $contentType,
            self::literalCstr($context, 'boundary=')
        );
        $pNull = $context->builder->icmp(Builder::INT_EQ, $p, $nullPtr);
        $context->builder->branchIf($pNull, $fail, $findBoundary);

        $context->builder->positionAtEnd($findBoundary);
        $p = $context->builder->inBoundsGEP($p, $sizeT->constInt(9, false));
        $context->builder->store($p, $pSlot);
        $context->builder->branch($skipWs);

        $context->builder->positionAtEnd($skipWs);
        $p = $context->builder->load($pSlot);
        $ch = $context->builder->load($p);
        $isWs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord(' '), false)),
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord("\t"), false))
        );
        $skipCont = $fn->appendBasicBlock('skip_cont');
        $context->builder->branchIf($isWs, $skipCont, $quoted);
        $context->builder->positionAtEnd($skipCont);
        $context->builder->store($context->builder->inBoundsGEP($p, $oneSize), $pSlot);
        $context->builder->branch($skipWs);

        $quotedBody = $fn->appendBasicBlock('quoted_body');

        $context->builder->positionAtEnd($quoted);
        $p = $context->builder->load($pSlot);
        $isQuote = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($p), $i8->constInt(ord('"'), false));
        $context->builder->branchIf($isQuote, $quotedBody, $unquoted);

        $context->builder->positionAtEnd($quotedBody);
        $p = $context->builder->inBoundsGEP($context->builder->load($pSlot), $oneSize);
        $context->builder->store($p, $pSlot);
        $context->builder->store($p, $startSlot);
        $qHead = $fn->appendBasicBlock('q_head');
        $qBody = $fn->appendBasicBlock('q_body');
        $qDone = $fn->appendBasicBlock('q_done');
        $context->builder->branch($qHead);
        $context->builder->positionAtEnd($qHead);
        $p = $context->builder->load($pSlot);
        $ch = $context->builder->load($p);
        $atEnd = $context->builder->icmp(Builder::INT_EQ, $ch, $zeroI8);
        $isQ = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('"'), false));
        $context->builder->branchIf($context->builder->or($atEnd, $isQ), $qDone, $qBody);
        $context->builder->positionAtEnd($qBody);
        $context->builder->store($context->builder->inBoundsGEP($p, $oneSize), $pSlot);
        $context->builder->branch($qHead);
        $context->builder->positionAtEnd($qDone);
        $start = $context->builder->load($startSlot);
        $endP = $context->builder->load($pSlot);
        $len = $context->builder->sub(
            $context->builder->ptrToInt($endP, $i64),
            $context->builder->ptrToInt($start, $i64)
        );
        $context->builder->store($len, $lenSlot);
        $hasClose = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($endP), $i8->constInt(ord('"'), false));
        $skipClose = $fn->appendBasicBlock('skip_close');
        $context->builder->branchIf($hasClose, $skipClose, $copyOk);
        $context->builder->positionAtEnd($skipClose);
        $context->builder->store($context->builder->inBoundsGEP($endP, $oneSize), $pSlot);
        $context->builder->branch($copyOk);

        $context->builder->positionAtEnd($unquoted);
        $p = $context->builder->load($pSlot);
        $context->builder->store($p, $startSlot);
        $uHead = $fn->appendBasicBlock('u_head');
        $uBody = $fn->appendBasicBlock('u_body');
        $uDone = $fn->appendBasicBlock('u_done');
        $context->builder->branch($uHead);
        $context->builder->positionAtEnd($uHead);
        $p = $context->builder->load($pSlot);
        $ch = $context->builder->load($p);
        $atEnd = $context->builder->icmp(Builder::INT_EQ, $ch, $zeroI8);
        $isStop = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord(';'), false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord(' '), false)),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord("\t"), false)),
                    $context->builder->or(
                        $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord("\r"), false)),
                        $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord("\n"), false))
                    )
                )
            )
        );
        $context->builder->branchIf($context->builder->or($atEnd, $isStop), $uDone, $uBody);
        $context->builder->positionAtEnd($uBody);
        $context->builder->store($context->builder->inBoundsGEP($p, $oneSize), $pSlot);
        $context->builder->branch($uHead);
        $context->builder->positionAtEnd($uDone);
        $start = $context->builder->load($startSlot);
        $endP = $context->builder->load($pSlot);
        $len = $context->builder->sub(
            $context->builder->ptrToInt($endP, $i64),
            $context->builder->ptrToInt($start, $i64)
        );
        $context->builder->store($len, $lenSlot);
        $context->builder->branch($copyOk);

        $context->builder->positionAtEnd($copyOk);
        $len = $context->builder->load($lenSlot);
        $zeroLen = $context->builder->icmp(Builder::INT_EQ, $len, $sizeT->constInt(0, false));
        $tooLong = $context->builder->icmp(Builder::INT_UGE, $len, $outLen);
        $context->builder->branchIf($context->builder->or($zeroLen, $tooLong), $fail, $done);

        $context->builder->positionAtEnd($done);
        $start = $context->builder->load($startSlot);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($out),
            $context->bytePtr($start),
            $len
        );
        $context->builder->store($zeroI8, $context->builder->inBoundsGEP($out, $len));
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitFindHeaderValue(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $nullPtr = $i8p->constNull();
        $zeroI32 = $i32->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);
        $twoSize = $sizeT->constInt(2, false);

        $headers = $fn->getParam(0);
        $name = $fn->getParam(1);
        $nameLen = $context->builder->call($context->lookupFunction('strlen'), $name);

        $lineSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $endSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $trimSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $valSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($headers, $lineSlot);

        $loopHead = $fn->appendBasicBlock('loop_head');
        $loopBody = $fn->appendBasicBlock('loop_body');
        $fail = $fn->appendBasicBlock('fail');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $line = $context->builder->load($lineSlot);
        $atEnd = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($line), $i8->constInt(0, false));
        $context->builder->branchIf($atEnd, $fail, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $line = $context->builder->load($lineSlot);
        $endFromStrstr = $context->builder->call(
            $context->lookupFunction('strstr'),
            $line,
            self::literalCstr($context, "\r\n")
        );
        $endNull = $context->builder->icmp(Builder::INT_EQ, $endFromStrstr, $nullPtr);
        $tryLf = $fn->appendBasicBlock('try_lf');
        $storeStrstrEnd = $fn->appendBasicBlock('store_strstr_end');
        $haveEnd = $fn->appendBasicBlock('have_end');
        $context->builder->branchIf($endNull, $tryLf, $storeStrstrEnd);

        $context->builder->positionAtEnd($storeStrstrEnd);
        $context->builder->store($endFromStrstr, $endSlot);
        $context->builder->branch($haveEnd);

        $useFullLen = $fn->appendBasicBlock('use_full_len');
        $storeLfEnd = $fn->appendBasicBlock('store_lf_end');

        $context->builder->positionAtEnd($tryLf);
        $line = $context->builder->load($lineSlot);
        $endFromLf = $context->builder->call(
            $context->lookupFunction('strchr'),
            $line,
            $i32->constInt(ord("\n"), false)
        );
        $lfNull = $context->builder->icmp(Builder::INT_EQ, $endFromLf, $nullPtr);
        $context->builder->branchIf($lfNull, $useFullLen, $storeLfEnd);

        $context->builder->positionAtEnd($storeLfEnd);
        $context->builder->store($endFromLf, $endSlot);
        $context->builder->branch($haveEnd);

        $context->builder->positionAtEnd($useFullLen);
        $line = $context->builder->load($lineSlot);
        $context->builder->store(
            $context->builder->inBoundsGEP($line, $context->builder->call($context->lookupFunction('strlen'), $line)),
            $endSlot
        );
        $context->builder->branch($haveEnd);

        $context->builder->positionAtEnd($haveEnd);
        $end = $context->builder->load($endSlot);
        $line = $context->builder->load($lineSlot);
        $colon = $context->builder->call(
            $context->lookupFunction('strchr'),
            $line,
            $i32->constInt(ord(':'), false)
        );
        $colonNull = $context->builder->icmp(Builder::INT_EQ, $colon, $nullPtr);
        $colonPastEnd = $context->builder->icmp(Builder::INT_UGE, $colon, $end);
        $badLine = $context->builder->or($colonNull, $colonPastEnd);
        $nextLine = $fn->appendBasicBlock('next_line');
        $checkName = $fn->appendBasicBlock('check_name');
        $context->builder->branchIf($badLine, $nextLine, $checkName);

        $context->builder->positionAtEnd($checkName);
        $lineNameLen = $context->builder->sub(
            $context->builder->ptrToInt($colon, $i64),
            $context->builder->ptrToInt($line, $i64)
        );
        $context->builder->store($lineNameLen, $trimSlot);
        $trimHead = $fn->appendBasicBlock('trim_head');
        $trimBody = $fn->appendBasicBlock('trim_body');
        $afterTrim = $fn->appendBasicBlock('after_trim');
        $context->builder->branch($trimHead);
        $context->builder->positionAtEnd($trimHead);
        $curLen = $context->builder->load($trimSlot);
        $canTrim = $context->builder->icmp(Builder::INT_UGT, $curLen, $sizeT->constInt(0, false));
        $context->builder->branchIf($canTrim, $trimBody, $afterTrim);
        $context->builder->positionAtEnd($trimBody);
        $line = $context->builder->load($lineSlot);
        $idx = $context->builder->sub($curLen, $oneSize);
        $ch = $context->builder->load($context->builder->inBoundsGEP($line, $idx));
        $isWs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord(' '), false)),
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord("\t"), false))
        );
        $trimCont = $fn->appendBasicBlock('trim_cont');
        $context->builder->branchIf($isWs, $trimCont, $afterTrim);
        $context->builder->positionAtEnd($trimCont);
        $context->builder->store($idx, $trimSlot);
        $context->builder->branch($trimHead);

        $context->builder->positionAtEnd($afterTrim);
        $line = $context->builder->load($lineSlot);
        $lineNameLen = $context->builder->load($trimSlot);
        $lenMatch = $context->builder->icmp(Builder::INT_EQ, $lineNameLen, $nameLen);
        $cmp = $context->builder->call(
            $context->lookupFunction('strncasecmp'),
            $line,
            $name,
            $nameLen
        );
        $nameMatch = $context->builder->and(
            $lenMatch,
            $context->builder->icmp(Builder::INT_EQ, $cmp, $zeroI32)
        );
        $returnVal = $fn->appendBasicBlock('return_val');
        $context->builder->branchIf($nameMatch, $returnVal, $nextLine);

        $context->builder->positionAtEnd($returnVal);
        $value = $context->builder->inBoundsGEP($colon, $oneSize);
        $skipVal = $fn->appendBasicBlock('skip_val');
        $valDone = $fn->appendBasicBlock('val_done');
        $context->builder->store($value, $valSlot);
        $context->builder->branch($skipVal);
        $context->builder->positionAtEnd($skipVal);
        $value = $context->builder->load($valSlot);
        $ch = $context->builder->load($value);
        $isWs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord(' '), false)),
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord("\t"), false))
        );
        $skipCont = $fn->appendBasicBlock('skip_val_cont');
        $context->builder->branchIf($isWs, $skipCont, $valDone);
        $context->builder->positionAtEnd($skipCont);
        $context->builder->store($context->builder->inBoundsGEP($value, $oneSize), $valSlot);
        $context->builder->branch($skipVal);
        $context->builder->positionAtEnd($valDone);
        $context->builder->returnValue($context->builder->load($valSlot));

        $context->builder->positionAtEnd($nextLine);
        $end = $context->builder->load($endSlot);
        $endNull2 = $context->builder->icmp(Builder::INT_EQ, $end, $nullPtr);
        $endCh = $context->builder->load($end);
        $endZero = $context->builder->icmp(Builder::INT_EQ, $endCh, $i8->constInt(0, false));
        $breakLoop = $context->builder->or($endNull2, $endZero);
        $advanceStep = $fn->appendBasicBlock('advance_step');
        $context->builder->branchIf($breakLoop, $fail, $advanceStep);
        $context->builder->positionAtEnd($advanceStep);
        $isCr = $context->builder->icmp(Builder::INT_EQ, $endCh, $i8->constInt(ord("\r"), false));
        $nextAfterCr = $context->builder->inBoundsGEP($end, $oneSize);
        $isCrLf = $context->builder->and(
            $isCr,
            $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->load($nextAfterCr),
                $i8->constInt(ord("\n"), false)
            )
        );
        $step = $context->builder->select(
            $isCrLf,
            $twoSize,
            $oneSize
        );
        $context->builder->store($context->builder->inBoundsGEP($end, $step), $lineSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullPtr);
    }

    private static function emitMultipartParam(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);
        $nullPtr = $i8p->constNull();
        $zeroI8 = $i8->constInt(0, false);

        $disposition = $fn->getParam(0);
        $param = $fn->getParam(1);
        $out = $fn->getParam(2);
        $outLen = $fn->getParam(3);

        $needleSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::NEEDLE_CAP));
        $pSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $needle = $context->builder->pointerCast($needleSlot, $i8p);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $needle,
            $sizeT->constInt(self::NEEDLE_CAP, false),
            self::literalCstr($context, '%s="'),
            $param
        );

        $fail = $fn->appendBasicBlock('fail');
        $found = $fn->appendBasicBlock('found');
        $p = $context->builder->call($context->lookupFunction('strstr'), $disposition, $needle);
        $pNull = $context->builder->icmp(Builder::INT_EQ, $p, $nullPtr);
        $context->builder->branchIf($pNull, $fail, $found);

        $context->builder->positionAtEnd($found);
        $needleLen = $context->builder->call($context->lookupFunction('strlen'), $needle);
        $p = $context->builder->inBoundsGEP($p, $needleLen);
        $start = $p;
        $context->builder->store($p, $pSlot);
        $loopHead = $fn->appendBasicBlock('loop_head');
        $loopBody = $fn->appendBasicBlock('loop_body');
        $loopDone = $fn->appendBasicBlock('loop_done');
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopHead);
        $p = $context->builder->load($pSlot);
        $ch = $context->builder->load($p);
        $atEnd = $context->builder->icmp(Builder::INT_EQ, $ch, $zeroI8);
        $isQuote = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('"'), false));
        $context->builder->branchIf($context->builder->or($atEnd, $isQuote), $loopDone, $loopBody);
        $context->builder->positionAtEnd($loopBody);
        $context->builder->store($context->builder->inBoundsGEP($p, $oneSize), $pSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $endP = $context->builder->load($pSlot);
        $len = $context->builder->sub(
            $context->builder->ptrToInt($endP, $i64),
            $context->builder->ptrToInt($start, $i64)
        );
        $tooLong = $context->builder->icmp(Builder::INT_UGE, $context->builder->add($len, $oneSize), $outLen);
        $copyOk = $fn->appendBasicBlock('copy_ok');
        $context->builder->branchIf($tooLong, $fail, $copyOk);
        $context->builder->positionAtEnd($copyOk);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($out),
            $context->bytePtr($start),
            $len
        );
        $context->builder->store($zeroI8, $context->builder->inBoundsGEP($out, $len));
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitSetFileEntry(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $nullPtr = $i8p->constNull();
        $zeroI8 = $i8->constInt(0, false);

        $files = $fn->getParam(0);
        $field = $fn->getParam(1);
        $filename = $fn->getParam(2);
        $partType = $fn->getParam(3);
        $content = $fn->getParam(4);
        $contentLen = $fn->getParam(5);

        $entryHt = $context->builder->call(
            $context->lookupFunction('__phpc_parse_str_ensure_child'),
            $files,
            $field
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_multipart_set_string_key'),
            $entryHt,
            self::literalCstr($context, 'name'),
            $filename
        );

        $defaultType = self::literalCstr($context, 'application/octet-stream');
        $checkType = $fn->appendBasicBlock('check_type');
        $useDefaultType = $fn->appendBasicBlock('use_default_type');
        $storeType = $fn->appendBasicBlock('store_type');
        $afterType = $fn->appendBasicBlock('after_type');
        $typeNull = $context->builder->icmp(Builder::INT_EQ, $partType, $nullPtr);
        $context->builder->branchIf($typeNull, $useDefaultType, $checkType);

        $context->builder->positionAtEnd($checkType);
        $typeEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($partType), $zeroI8);
        $context->builder->branchIf($typeEmpty, $useDefaultType, $storeType);

        $context->builder->positionAtEnd($useDefaultType);
        $context->builder->branch($afterType);

        $context->builder->positionAtEnd($storeType);
        $context->builder->branch($afterType);

        $context->builder->positionAtEnd($afterType);
        $typeVal = $context->builder->phi($i8p, 'part_type');
        $typeVal->addIncoming($defaultType, $useDefaultType);
        $typeVal->addIncoming($partType, $storeType);
        $context->builder->call(
            $context->lookupFunction('__phpc_multipart_set_string_key'),
            $entryHt,
            self::literalCstr($context, 'type'),
            $typeVal
        );

        $tmpSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(32));
        $tmpPath = $context->builder->pointerCast($tmpSlot, $i8p);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($tmpPath),
            $context->bytePtr(self::literalCstr($context, '/tmp/phpc_upload_XXXXXX')),
            $sizeT->constInt(32, false)
        );

        $errFd = $fn->appendBasicBlock('err_fd');
        $haveFd = $fn->appendBasicBlock('have_fd');
        $fd = $context->builder->call($context->lookupFunction('mkstemp'), $tmpPath);
        $fdBad = $context->builder->icmp(Builder::INT_SLT, $fd, $i32->constInt(0, true));
        $context->builder->branchIf($fdBad, $errFd, $haveFd);

        $context->builder->positionAtEnd($errFd);
        $context->builder->call(
            $context->lookupFunction('__phpc_multipart_set_string_key'),
            $entryHt,
            self::literalCstr($context, 'error'),
            self::literalCstr($context, '1')
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($haveFd);
        $fp = $context->builder->call(
            $context->lookupFunction('fdopen'),
            $fd,
            self::literalCstr($context, 'wb')
        );
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $errFp = $fn->appendBasicBlock('err_fp');
        $haveFp = $fn->appendBasicBlock('have_fp');
        $context->builder->branchIf($fpNull, $errFp, $haveFp);

        $context->builder->positionAtEnd($errFp);
        $context->builder->call(
            $context->lookupFunction('__phpc_multipart_set_string_key'),
            $entryHt,
            self::literalCstr($context, 'error'),
            self::literalCstr($context, '1')
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($haveFp);
        $hasContent = $context->builder->icmp(Builder::INT_UGT, $contentLen, $sizeT->constInt(0, false));
        $skipWrite = $fn->appendBasicBlock('skip_write');
        $doWrite = $fn->appendBasicBlock('do_write');
        $context->builder->branchIf($hasContent, $doWrite, $skipWrite);

        $context->builder->positionAtEnd($doWrite);
        $written = $context->builder->call(
            $context->lookupFunction('fwrite'),
            $context->bytePtr($content),
            $sizeT->constInt(1, false),
            $contentLen,
            $fp
        );
        $writeFail = $context->builder->icmp(
            Builder::INT_NE,
            $written,
            $contentLen
        );
        $errWrite = $fn->appendBasicBlock('err_write');
        $writeOk = $fn->appendBasicBlock('write_ok');
        $context->builder->branchIf($writeFail, $errWrite, $writeOk);

        $context->builder->positionAtEnd($errWrite);
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        $context->builder->call($context->lookupFunction('unlink'), $tmpPath);
        $context->builder->call(
            $context->lookupFunction('__phpc_multipart_set_string_key'),
            $entryHt,
            self::literalCstr($context, 'error'),
            self::literalCstr($context, '1')
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($writeOk);
        $context->builder->branch($skipWrite);

        $context->builder->positionAtEnd($skipWrite);
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        $context->builder->call(
            $context->lookupFunction('__phpc_multipart_set_string_key'),
            $entryHt,
            self::literalCstr($context, 'tmp_name'),
            $tmpPath
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_multipart_set_string_key'),
            $entryHt,
            self::literalCstr($context, 'error'),
            self::literalCstr($context, '0')
        );
        $sizeSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(32));
        $sizeBuf = $context->builder->pointerCast($sizeSlot, $i8p);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $sizeBuf,
            $sizeT->constInt(32, false),
            self::literalCstr($context, '%zu'),
            $contentLen
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_multipart_set_string_key'),
            $entryHt,
            self::literalCstr($context, 'size'),
            $sizeBuf
        );
        $context->builder->returnVoid();
    }

    private static function emitNormalizeBodyNewlines(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $nullPtr = $i8p->constNull();
        $oneSize = $sizeT->constInt(1, false);
        $zeroI8 = $i8->constInt(0, false);

        $body = $fn->getParam(0);
        $outLenPtr = $fn->getParam(1);

        $fail = $fn->appendBasicBlock('fail');
        $work = $fn->appendBasicBlock('work');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $body, $nullPtr);
        $context->builder->branchIf($isNull, $fail, $work);

        $context->builder->positionAtEnd($fail);
        $context->builder->store($sizeT->constInt(0, false), $outLenPtr);
        $context->builder->returnValue($nullPtr);

        $context->builder->positionAtEnd($work);
        $len = $context->builder->call($context->lookupFunction('strlen'), $body);
        $copy = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->add($len, $oneSize)
        );
        $copyNull = $context->builder->icmp(Builder::INT_EQ, $copy, $nullPtr);
        $loopInit = $fn->appendBasicBlock('loop_init');
        $context->builder->branchIf($copyNull, $fail, $loopInit);

        $context->builder->positionAtEnd($loopInit);
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $wSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $context->builder->store($sizeT->constInt(0, false), $wSlot);
        $loopHead = $fn->appendBasicBlock('loop_head');
        $context->builder->branch($loopHead);

        $loopBody = $fn->appendBasicBlock('loop_body');
        $plain = $fn->appendBasicBlock('plain');
        $cr = $fn->appendBasicBlock('cr');
        $loopDone = $fn->appendBasicBlock('loop_done');

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $done = $context->builder->icmp(Builder::INT_UGE, $i, $len);
        $context->builder->branchIf($done, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($body, $i));
        $isCr = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord("\r"), false));
        $context->builder->branchIf($isCr, $cr, $plain);

        $context->builder->positionAtEnd($cr);
        $next = $context->builder->add($i, $oneSize);
        $hasLf = $context->builder->and(
            $context->builder->icmp(Builder::INT_ULT, $next, $len),
            $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->load($context->builder->inBoundsGEP($body, $next)),
                $i8->constInt(ord("\n"), false)
            )
        );
        $skipLf = $fn->appendBasicBlock('skip_lf');
        $storeNl = $fn->appendBasicBlock('store_nl');
        $context->builder->branchIf($hasLf, $skipLf, $plain);
        $context->builder->positionAtEnd($skipLf);
        $context->builder->store($context->builder->add($next, $oneSize), $iSlot);
        $context->builder->branch($storeNl);
        $context->builder->positionAtEnd($storeNl);
        $w = $context->builder->load($wSlot);
        $context->builder->store($i8->constInt(ord("\n"), false), $context->builder->inBoundsGEP($copy, $w));
        $context->builder->store($context->builder->add($w, $oneSize), $wSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($plain);
        $w = $context->builder->load($wSlot);
        $context->builder->store($ch, $context->builder->inBoundsGEP($copy, $w));
        $context->builder->store($context->builder->add($w, $oneSize), $wSlot);
        $context->builder->store($context->builder->add($i, $oneSize), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $w = $context->builder->load($wSlot);
        $context->builder->store($zeroI8, $context->builder->inBoundsGEP($copy, $w));
        $context->builder->store($w, $outLenPtr);
        $context->builder->returnValue($context->builder->pointerCast($copy, $i8p));
    }

    private static function emitParseMultipartPost(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $maxBody = $sizeT->constInt(self::MAX_BODY, false);
        $nullPtr = $i8p->constNull();
        $zeroI8 = $i8->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);
        $twoSize = $sizeT->constInt(2, false);
        $amp = $i8->constInt(ord('&'), false);
        $zeroI32 = $i32->constInt(0, false);

        $post = $fn->getParam(0);
        $files = $fn->getParam(1);
        $contentType = $fn->getParam(2);
        $bodyIn = $fn->getParam(3);

        $bodyLenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $boundarySlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::BOUNDARY_CAP));
        $boundary = $context->builder->pointerCast($boundarySlot, $i8p);
        $delimSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::DELIM_CAP));
        $delim = $context->builder->pointerCast($delimSlot, $i8p);
        $cursorSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $fieldSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::FIELD_CAP));
        $field = $context->builder->pointerCast($fieldSlot, $i8p);
        $contentLenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $filenameSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::FIELD_CAP));
        $filename = $context->builder->pointerCast($filenameSlot, $i8p);
        $pairSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::PAIR_CAP));
        $pairBuf = $context->builder->pointerCast($pairSlot, $i8p);
        $partStartSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $partEndSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $headersEndSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $dispositionSlot = BasicBlockHelper::entryAlloca($context, $i8p);

        $ret = $fn->appendBasicBlock('ret');
        $work = $fn->appendBasicBlock('work');

        $bodyNull = $context->builder->icmp(Builder::INT_EQ, $bodyIn, $nullPtr);
        $bodyEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($bodyIn), $zeroI8);
        $context->builder->branchIf($context->builder->or($bodyNull, $bodyEmpty), $ret, $work);

        $context->builder->positionAtEnd($work);
        $normalized = $context->builder->call(
            $context->lookupFunction('__phpc_multipart_normalize_body_newlines'),
            $bodyIn,
            $bodyLenSlot
        );
        $normNull = $context->builder->icmp(Builder::INT_EQ, $normalized, $nullPtr);
        $checkLen = $fn->appendBasicBlock('check_len');
        $context->builder->branchIf($normNull, $ret, $checkLen);

        $context->builder->positionAtEnd($checkLen);
        $bodyLen = $context->builder->load($bodyLenSlot);
        $tooLong = $context->builder->icmp(Builder::INT_UGT, $bodyLen, $maxBody);
        $freeRet = $fn->appendBasicBlock('free_ret');
        $parse = $fn->appendBasicBlock('parse');
        $context->builder->branchIf($tooLong, $freeRet, $parse);

        $context->builder->positionAtEnd($freeRet);
        $context->builder->call($context->lookupFunction('free'), $normalized);
        $context->builder->branch($ret);

        $context->builder->positionAtEnd($parse);
        $okBoundary = $context->builder->call(
            $context->lookupFunction('__phpc_multipart_extract_boundary'),
            $contentType,
            $boundary,
            $sizeT->constInt(self::BOUNDARY_CAP, false)
        );
        $boundaryFail = $fn->appendBasicBlock('boundary_fail');
        $haveBoundary = $fn->appendBasicBlock('have_boundary');
        $context->builder->branchIf($context->i32Success($okBoundary), $haveBoundary, $boundaryFail);

        $context->builder->positionAtEnd($boundaryFail);
        $context->builder->call($context->lookupFunction('free'), $normalized);
        $context->builder->branch($ret);

        $context->builder->positionAtEnd($haveBoundary);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $delim,
            $sizeT->constInt(self::DELIM_CAP, false),
            self::literalCstr($context, '--%s'),
            $boundary
        );
        $delimLen = $context->builder->call($context->lookupFunction('strlen'), $delim);
        $body = $normalized;
        $end = $context->builder->inBoundsGEP($body, $bodyLen);
        $context->builder->store($body, $cursorSlot);

        $partLoop = $fn->appendBasicBlock('part_loop');
        $nextPart = $fn->appendBasicBlock('next_part');
        $partDone = $fn->appendBasicBlock('part_done');
        $havePart = $fn->appendBasicBlock('have_part');
        $findPartEnd = $fn->appendBasicBlock('find_part_end');
        $skipPart = $fn->appendBasicBlock('skip_part');
        $processPart = $fn->appendBasicBlock('process_part');
        $haveDisp = $fn->appendBasicBlock('have_disp');
        $haveField = $fn->appendBasicBlock('have_field');
        $filePart = $fn->appendBasicBlock('file_part');
        $fieldPart = $fn->appendBasicBlock('field_part');
        $buildPair = $fn->appendBasicBlock('build_pair');
        $haveCopy = $fn->appendBasicBlock('have_copy');

        $context->builder->branch($partLoop);

        $context->builder->positionAtEnd($partLoop);
        $cursor = $context->builder->load($cursorSlot);
        $pastEnd = $context->builder->icmp(Builder::INT_UGE, $cursor, $end);
        $context->builder->branchIf($pastEnd, $partDone, $nextPart);

        $context->builder->positionAtEnd($nextPart);
        $partStart = $context->builder->call($context->lookupFunction('strstr'), $cursor, $delim);
        $partStartNull = $context->builder->icmp(Builder::INT_EQ, $partStart, $nullPtr);
        $context->builder->branchIf($partStartNull, $partDone, $havePart);

        $context->builder->positionAtEnd($havePart);
        $partStart = $context->builder->inBoundsGEP($partStart, $delimLen);
        $context->builder->store($partStart, $partStartSlot);
        $isCrLf = $context->builder->and(
            $context->builder->icmp(Builder::INT_ULT, $partStart, $end),
            $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, $context->builder->load($partStart), $i8->constInt(ord("\r"), false)),
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $context->builder->load($context->builder->inBoundsGEP($partStart, $oneSize)),
                    $i8->constInt(ord("\n"), false)
                )
            )
        );
        $skipCrLf = $fn->appendBasicBlock('skip_crlf');
        $checkLf = $fn->appendBasicBlock('check_lf');
        $context->builder->branchIf($isCrLf, $skipCrLf, $checkLf);
        $context->builder->positionAtEnd($skipCrLf);
        $partStart = $context->builder->inBoundsGEP($context->builder->load($partStartSlot), $twoSize);
        $context->builder->store($partStart, $partStartSlot);
        $afterSkip = $fn->appendBasicBlock('after_skip');
        $context->builder->branch($afterSkip);
        $context->builder->positionAtEnd($checkLf);
        $partStart = $context->builder->load($partStartSlot);
        $isLf = $context->builder->and(
            $context->builder->icmp(Builder::INT_ULT, $partStart, $end),
            $context->builder->icmp(Builder::INT_EQ, $context->builder->load($partStart), $i8->constInt(ord("\n"), false))
        );
        $skipLf = $fn->appendBasicBlock('skip_lf_only');
        $context->builder->branchIf($isLf, $skipLf, $afterSkip);
        $context->builder->positionAtEnd($skipLf);
        $partStart = $context->builder->inBoundsGEP($context->builder->load($partStartSlot), $oneSize);
        $context->builder->store($partStart, $partStartSlot);
        $context->builder->branch($afterSkip);

        $context->builder->positionAtEnd($afterSkip);
        $partStart = $context->builder->load($partStartSlot);
        $canClose = $context->builder->icmp(
            Builder::INT_ULE,
            $context->builder->inBoundsGEP($partStart, $twoSize),
            $end
        );
        $isClose = $context->builder->and(
            $canClose,
            $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, $context->builder->load($partStart), $i8->constInt(ord('-'), false)),
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $context->builder->load($context->builder->inBoundsGEP($partStart, $oneSize)),
                    $i8->constInt(ord('-'), false)
                )
            )
        );
        $context->builder->branchIf($isClose, $partDone, $findPartEnd);

        $context->builder->positionAtEnd($findPartEnd);
        $partStart = $context->builder->load($partStartSlot);
        $partEnd = $context->builder->call($context->lookupFunction('strstr'), $partStart, $delim);
        $partEndNull = $context->builder->icmp(Builder::INT_EQ, $partEnd, $nullPtr);
        $partEnd = $context->builder->select($partEndNull, $end, $partEnd);
        $context->builder->store($partEnd, $partEndSlot);
        $headersEnd = $context->builder->call(
            $context->lookupFunction('strstr'),
            $partStart,
            self::literalCstr($context, "\n\n")
        );
        $context->builder->store($headersEnd, $headersEndSlot);
        $headersBad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $headersEnd, $nullPtr),
            $context->builder->icmp(Builder::INT_UGE, $headersEnd, $partEnd)
        );
        $context->builder->branchIf($headersBad, $skipPart, $processPart);

        $context->builder->positionAtEnd($processPart);
        $partStart = $context->builder->load($partStartSlot);
        $headersEnd = $context->builder->inBoundsGEP($context->builder->load($headersEndSlot), $twoSize);
        $context->builder->store($headersEnd, $headersEndSlot);
        $disposition = $context->builder->call(
            $context->lookupFunction('__phpc_multipart_find_header_value'),
            $partStart,
            self::literalCstr($context, 'Content-Disposition')
        );
        $context->builder->store($disposition, $dispositionSlot);
        $dispNull = $context->builder->icmp(Builder::INT_EQ, $disposition, $nullPtr);
        $context->builder->branchIf($dispNull, $skipPart, $haveDisp);

        $context->builder->positionAtEnd($haveDisp);
        $disposition = $context->builder->load($dispositionSlot);
        $okName = $context->builder->call(
            $context->lookupFunction('__phpc_multipart_param'),
            $disposition,
            self::literalCstr($context, 'name'),
            $field,
            $sizeT->constInt(self::FIELD_CAP, false)
        );
        $fieldEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($field), $zeroI8);
        $nameBad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $okName, $zeroI32),
            $fieldEmpty
        );
        $context->builder->branchIf($nameBad, $skipPart, $haveField);

        $context->builder->positionAtEnd($haveField);
        $partEnd = $context->builder->load($partEndSlot);
        $headersEnd = $context->builder->load($headersEndSlot);
        $contentLen = $context->builder->sub(
            $context->builder->ptrToInt($partEnd, $i64),
            $context->builder->ptrToInt($headersEnd, $i64)
        );
        $context->builder->store(
            $context->builder->trunc($contentLen, $sizeT),
            $contentLenSlot
        );

        $trimHead = $fn->appendBasicBlock('trim_head');
        $trimBody = $fn->appendBasicBlock('trim_body');
        $afterTrim = $fn->appendBasicBlock('after_trim');
        $context->builder->branch($trimHead);
        $context->builder->positionAtEnd($trimHead);
        $cl = $context->builder->load($contentLenSlot);
        $canTrim = $context->builder->icmp(Builder::INT_UGT, $cl, $sizeT->constInt(0, false));
        $context->builder->branchIf($canTrim, $trimBody, $afterTrim);
        $context->builder->positionAtEnd($trimBody);
        $cl = $context->builder->load($contentLenSlot);
        $headersEnd = $context->builder->load($headersEndSlot);
        $last = $context->builder->load($context->builder->inBoundsGEP($headersEnd, $context->builder->sub($cl, $oneSize)));
        $isTrail = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $last, $i8->constInt(ord("\r"), false)),
            $context->builder->icmp(Builder::INT_EQ, $last, $i8->constInt(ord("\n"), false))
        );
        $trimCont = $fn->appendBasicBlock('trim_cont');
        $context->builder->branchIf($isTrail, $trimCont, $afterTrim);
        $context->builder->positionAtEnd($trimCont);
        $context->builder->store($context->builder->sub($cl, $oneSize), $contentLenSlot);
        $context->builder->branch($trimHead);

        $context->builder->positionAtEnd($afterTrim);
        $contentLen = $context->builder->load($contentLenSlot);
        $disposition = $context->builder->load($dispositionSlot);
        $hasFile = $context->builder->call(
            $context->lookupFunction('__phpc_multipart_param'),
            $disposition,
            self::literalCstr($context, 'filename'),
            $filename,
            $sizeT->constInt(self::FIELD_CAP, false)
        );
        $isFile = $context->builder->icmp(Builder::INT_NE, $hasFile, $zeroI32);
        $context->builder->branchIf($isFile, $filePart, $fieldPart);

        $context->builder->positionAtEnd($filePart);
        $partStart = $context->builder->load($partStartSlot);
        $headersEnd = $context->builder->load($headersEndSlot);
        $contentLen = $context->builder->load($contentLenSlot);
        $partType = $context->builder->call(
            $context->lookupFunction('__phpc_multipart_find_header_value'),
            $partStart,
            self::literalCstr($context, 'Content-Type')
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_multipart_set_file_entry'),
            $files,
            $field,
            $filename,
            $partType,
            $headersEnd,
            $contentLen
        );
        $context->builder->branch($skipPart);

        $context->builder->positionAtEnd($fieldPart);
        $contentLen = $context->builder->load($contentLenSlot);
        $fieldLen = $context->builder->call($context->lookupFunction('strlen'), $field);
        $pairTooLong = $context->builder->icmp(
            Builder::INT_UGE,
            $context->builder->add($context->builder->add($contentLen, $fieldLen), $twoSize),
            $sizeT->constInt(self::PAIR_CAP, false)
        );
        $context->builder->branchIf($pairTooLong, $skipPart, $buildPair);

        $context->builder->positionAtEnd($buildPair);
        $contentLen = $context->builder->load($contentLenSlot);
        $copy = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->add($contentLen, $oneSize)
        );
        $copyNull = $context->builder->icmp(Builder::INT_EQ, $copy, $nullPtr);
        $context->builder->branchIf($copyNull, $skipPart, $haveCopy);

        $context->builder->positionAtEnd($haveCopy);
        $headersEnd = $context->builder->load($headersEndSlot);
        $contentLen = $context->builder->load($contentLenSlot);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($copy),
            $context->bytePtr($headersEnd),
            $contentLen
        );
        $context->builder->store($zeroI8, $context->builder->inBoundsGEP($copy, $contentLen));
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $pairBuf,
            $sizeT->constInt(self::PAIR_CAP, false),
            self::literalCstr($context, '%s=%s'),
            $field,
            $copy
        );
        $context->builder->call($context->lookupFunction('free'), $copy);
        $context->builder->call(
            $context->lookupFunction('__phpc_parse_str_parse_delimited_pairs'),
            $post,
            $pairBuf,
            $amp,
            $zeroI32
        );
        $context->builder->branch($skipPart);

        $context->builder->positionAtEnd($skipPart);
        $context->builder->store($context->builder->load($partEndSlot), $cursorSlot);
        $context->builder->branch($partLoop);

        $context->builder->positionAtEnd($partDone);
        $context->builder->call($context->lookupFunction('free'), $normalized);
        $context->builder->branch($ret);

        $context->builder->positionAtEnd($ret);
        $context->builder->returnVoid();
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
