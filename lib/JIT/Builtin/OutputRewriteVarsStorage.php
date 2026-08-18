<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM BSS storage for output rewrite blob/tags/hosts (#27566).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\OutputRewriteVarsJitHelper} string
 * statics abort under thin AOT (`__string__alloc` via listPairs). Keep the table
 * in module globals.
 *
 * Apply uses {@see GLOBAL_URL_APP} (name=value&…) built at emitAdd — avoids
 * NestedJIT blob parse and the blob_len persistence gap under thin AOT.
 */
final class OutputRewriteVarsStorage
{
    public const GLOBAL_BLOB = '__phpc_orv_blob';

    public const GLOBAL_BLOB_LEN = '__phpc_orv_blob_len';

    public const GLOBAL_TAGS = '__phpc_orv_tags';

    public const GLOBAL_TAGS_LEN = '__phpc_orv_tags_len';

    public const GLOBAL_HOSTS = '__phpc_orv_hosts';

    public const GLOBAL_HOSTS_LEN = '__phpc_orv_hosts_len';

    /** Prebuilt query fragment for URL-Rewriter apply (php-src url_app). */
    public const GLOBAL_URL_APP = '__phpc_orv_url_app';

    public const GLOBAL_URL_APP_LEN = '__phpc_orv_url_app_len';

    private const BLOB_CAP = 4095;

    private const TAGS_CAP = 255;

    private const HOSTS_CAP = 255;

    private const URL_APP_CAP = 1023;

    public static function ensureGlobals(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        self::ensureBuf($context, self::GLOBAL_BLOB, self::BLOB_CAP + 1, $i8);
        self::ensureLen($context, self::GLOBAL_BLOB_LEN, $i64);
        self::ensureBuf($context, self::GLOBAL_TAGS, self::TAGS_CAP + 1, $i8);
        self::ensureLen($context, self::GLOBAL_TAGS_LEN, $i64);
        self::ensureBuf($context, self::GLOBAL_HOSTS, self::HOSTS_CAP + 1, $i8);
        self::ensureLen($context, self::GLOBAL_HOSTS_LEN, $i64);
        self::ensureBuf($context, self::GLOBAL_URL_APP, self::URL_APP_CAP + 1, $i8);
        self::ensureLen($context, self::GLOBAL_URL_APP_LEN, $i64);
    }

    public static function bufPtrPublic(Context $context, string $name): Value
    {
        return self::bufPtr($context, $name);
    }

    public static function lenPtrPublic(Context $context, string $name): Value
    {
        return self::lenPtr($context, $name);
    }

    public static function ensureLibc(Context $context): void
    {
        \PHPCompiler\JIT\LibcExtern::ensureMemcpyDecl($context);
        // malloc after LibcExtern always-on drop (#32273).
        \PHPCompiler\JIT\LibcExtern::ensureMallocFamily($context);
    }

    /** Append name\\x1Evalue (with \\x1D separator when blob non-empty). */
    public static function emitAdd(Context $context, Value $nameStr, Value $valueStr): void
    {
        self::ensureGlobals($context);
        self::ensureLibc($context);
        self::ensureDefaultTags($context);

        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');

        $nameLen = $context->builder->load($context->builder->structGep($nameStr, $map['length']));
        $namePtr = $context->builder->pointerCast($context->builder->structGep($nameStr, $map['value']), $i8p);
        $valueLen = $context->builder->load($context->builder->structGep($valueStr, $map['length']));
        $valuePtr = $context->builder->pointerCast($context->builder->structGep($valueStr, $map['value']), $i8p);

        $lenPtr = self::lenPtr($context, self::GLOBAL_BLOB_LEN);
        $pos = $context->builder->load($lenPtr);
        $row = self::bufPtr($context, self::GLOBAL_BLOB);
        $cap = $i64->constInt(self::BLOB_CAP, false);

        $needSep = $context->builder->icmp(Builder::INT_UGT, $pos, $i64->constInt(0, false));
        $sepBytes = $context->builder->select($needSep, $i64->constInt(1, false), $i64->constInt(0, false));
        $need = $context->builder->add(
            $context->builder->add($nameLen, $valueLen),
            $context->builder->add($sepBytes, $i64->constInt(1, false))
        );
        $room = $context->builder->sub($cap, $pos);
        $fits = $context->builder->icmp(Builder::INT_ULE, $need, $room);

        $fn = $context->builder->getInsertBlock()->getParent();
        $skip = $fn->appendBasicBlock('orv_add_skip');
        $work = $fn->appendBasicBlock('orv_add_work');
        $done = $fn->appendBasicBlock('orv_add_done');
        $context->builder->branchIf($fits, $work, $skip);

        $context->builder->positionAtEnd($work);
        $sepBb = $fn->appendBasicBlock('orv_add_sep');
        $body = $fn->appendBasicBlock('orv_add_body');
        $context->builder->branchIf($needSep, $sepBb, $body);
        $context->builder->positionAtEnd($sepBb);
        $context->builder->store($i8->constInt(0x1D, false), $context->builder->inBoundsGEP($row, $pos));
        $curAfterSep = $context->builder->add($pos, $i64->constInt(1, false));
        $context->builder->branch($body);
        $context->builder->positionAtEnd($body);
        $curPhi = $context->builder->phi($i64, 'orv_cur');
        $curPhi->addIncoming($pos, $work);
        $curPhi->addIncoming($curAfterSep, $sepBb);

        $destName = $context->builder->inBoundsGEP($row, $curPhi);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($destName),
            $context->bytePtr($namePtr),
            $context->builder->trunc($nameLen, $sizeT)
        );
        $afterName = $context->builder->add($curPhi, $nameLen);
        $context->builder->store($i8->constInt(0x1E, false), $context->builder->inBoundsGEP($row, $afterName));
        $afterFs = $context->builder->add($afterName, $i64->constInt(1, false));
        $destVal = $context->builder->inBoundsGEP($row, $afterFs);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($destVal),
            $context->bytePtr($valuePtr),
            $context->builder->trunc($valueLen, $sizeT)
        );
        $newPos = $context->builder->add($afterFs, $valueLen);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($row, $newPos));
        $context->builder->store($newPos, $lenPtr);
        self::emitAppendUrlApp($context, $nameStr, $valueStr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    public static function emitReset(Context $context): void
    {
        self::ensureGlobals($context);
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $context->builder->store($i64->constInt(0, false), self::lenPtr($context, self::GLOBAL_BLOB_LEN));
        $context->builder->store($i8->constInt(0, false), self::bufPtr($context, self::GLOBAL_BLOB));
        $context->builder->store($i64->constInt(0, false), self::lenPtr($context, self::GLOBAL_URL_APP_LEN));
        $context->builder->store($i8->constInt(0, false), self::bufPtr($context, self::GLOBAL_URL_APP));
    }

    /** Append name=value (&-separated) to url_app for LLVM apply (#27566). */
    private static function emitAppendUrlApp(Context $context, Value $nameStr, Value $valueStr): void
    {
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $nameLen = $context->builder->load($context->builder->structGep($nameStr, $map['length']));
        $namePtr = $context->builder->pointerCast($context->builder->structGep($nameStr, $map['value']), $i8p);
        $valueLen = $context->builder->load($context->builder->structGep($valueStr, $map['length']));
        $valuePtr = $context->builder->pointerCast($context->builder->structGep($valueStr, $map['value']), $i8p);
        $lenPtr = self::lenPtr($context, self::GLOBAL_URL_APP_LEN);
        $pos = $context->builder->load($lenPtr);
        $row = self::bufPtr($context, self::GLOBAL_URL_APP);
        $cap = $i64->constInt(self::URL_APP_CAP, false);
        $needSep = $context->builder->icmp(Builder::INT_UGT, $pos, $i64->constInt(0, false));
        $sepBytes = $context->builder->select($needSep, $i64->constInt(1, false), $i64->constInt(0, false));
        $need = $context->builder->add(
            $context->builder->add($nameLen, $valueLen),
            $context->builder->add($sepBytes, $i64->constInt(1, false))
        );
        $room = $context->builder->sub($cap, $pos);
        $fits = $context->builder->icmp(Builder::INT_ULE, $need, $room);
        $fn = $context->builder->getInsertBlock()->getParent();
        $skip = $fn->appendBasicBlock('orv_urlapp_skip');
        $work = $fn->appendBasicBlock('orv_urlapp_work');
        $done = $fn->appendBasicBlock('orv_urlapp_done');
        $context->builder->branchIf($fits, $work, $skip);
        $context->builder->positionAtEnd($work);
        $sepBb = $fn->appendBasicBlock('orv_urlapp_sep');
        $body = $fn->appendBasicBlock('orv_urlapp_body');
        $context->builder->branchIf($needSep, $sepBb, $body);
        $context->builder->positionAtEnd($sepBb);
        $context->builder->store($i8->constInt(\ord('&'), false), $context->builder->inBoundsGEP($row, $pos));
        $curAfterSep = $context->builder->add($pos, $i64->constInt(1, false));
        $context->builder->branch($body);
        $context->builder->positionAtEnd($body);
        $curPhi = $context->builder->phi($i64, 'orv_urlapp_cur');
        $curPhi->addIncoming($pos, $work);
        $curPhi->addIncoming($curAfterSep, $sepBb);
        $destName = $context->builder->inBoundsGEP($row, $curPhi);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($destName),
            $context->bytePtr($namePtr),
            $context->builder->trunc($nameLen, $sizeT)
        );
        $afterName = $context->builder->add($curPhi, $nameLen);
        $context->builder->store($i8->constInt(\ord('='), false), $context->builder->inBoundsGEP($row, $afterName));
        $afterEq = $context->builder->add($afterName, $i64->constInt(1, false));
        $destVal = $context->builder->inBoundsGEP($row, $afterEq);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($destVal),
            $context->bytePtr($valuePtr),
            $context->builder->trunc($valueLen, $sizeT)
        );
        $newPos = $context->builder->add($afterEq, $valueLen);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($row, $newPos));
        $context->builder->store($newPos, $lenPtr);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($skip);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    public static function emitSetTags(Context $context, Value $tagsStr): void
    {
        self::emitStoreString($context, $tagsStr, self::GLOBAL_TAGS, self::GLOBAL_TAGS_LEN, self::TAGS_CAP);
    }

    public static function emitSetHosts(Context $context, Value $hostsStr): void
    {
        self::emitStoreString($context, $hostsStr, self::GLOBAL_HOSTS, self::GLOBAL_HOSTS_LEN, self::HOSTS_CAP);
    }

    public static function stringFromGlobal(Context $context, string $bufName, string $lenName): Value
    {
        self::ensureGlobals($context);
        self::ensureLibc($context);
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $len = $context->builder->load(self::lenPtr($context, $lenName));
        $row = self::bufPtr($context, $bufName);
        $allocLen = $context->builder->add($len, $i64->constInt(1, false));
        $copy = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->trunc($allocLen, $sizeT)
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $copy,
            $context->bytePtr($row),
            $context->builder->trunc($len, $sizeT)
        );
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(0, false),
            $context->builder->inBoundsGEP($context->builder->pointerCast($copy, $i8p), $len)
        );

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $context->builder->pointerCast($copy, $i8p)
        );
    }

    private static function emitStoreString(
        Context $context,
        Value $str,
        string $bufName,
        string $lenName,
        int $cap
    ): void {
        self::ensureGlobals($context);
        self::ensureLibc($context);
        $map = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $slen = $context->builder->load($context->builder->structGep($str, $map['length']));
        $sptr = $context->builder->pointerCast($context->builder->structGep($str, $map['value']), $i8p);
        $useLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $slen, $i64->constInt($cap, false)),
            $i64->constInt($cap, false),
            $slen
        );
        $row = self::bufPtr($context, $bufName);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($row),
            $context->bytePtr($sptr),
            $context->builder->trunc($useLen, $sizeT)
        );
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(0, false),
            $context->builder->inBoundsGEP($row, $useLen)
        );
        $context->builder->store($useLen, self::lenPtr($context, $lenName));
    }

    private static function ensureDefaultTags(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $lenPtr = self::lenPtr($context, self::GLOBAL_TAGS_LEN);
        $len = $context->builder->load($lenPtr);
        $empty = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(0, false));
        $fn = $context->builder->getInsertBlock()->getParent();
        $setBb = $fn->appendBasicBlock('orv_def_tags');
        $contBb = $fn->appendBasicBlock('orv_def_cont');
        $context->builder->branchIf($empty, $setBb, $contBb);
        $context->builder->positionAtEnd($setBb);
        $default = $context->constantFromString('form=');
        $i8p = $context->getTypeFromString('int8*');
        $src = $context->builder->pointerCast($default, $i8p);
        $row = self::bufPtr($context, self::GLOBAL_TAGS);
        $n = $i64->constInt(5, false);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($row),
            $context->bytePtr($src),
            $context->builder->trunc($n, $context->getTypeFromString('size_t'))
        );
        $context->builder->store(
            $context->getTypeFromString('int8')->constInt(0, false),
            $context->builder->inBoundsGEP($row, $n)
        );
        $context->builder->store($n, $lenPtr);
        $context->builder->branch($contBb);
        $context->builder->positionAtEnd($contBb);
    }

    private static function ensureBuf(Context $context, string $name, int $size, $i8): void
    {
        if (null !== $context->module->getNamedGlobal($name)) {
            return;
        }
        $ty = $i8->arrayType($size);
        $g = $context->module->addGlobal($ty, $name);
        $g->setInitializer($ty->constNull());
    }

    private static function ensureLen(Context $context, string $name, $i64): void
    {
        if (null !== $context->module->getNamedGlobal($name)) {
            return;
        }
        $g = $context->module->addGlobal($i64, $name);
        $g->setInitializer($i64->constInt(0, false));
    }

    private static function bufPtr(Context $context, string $name): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('OutputRewriteVarsStorage missing '.$name);
        }
        // GEP 0,0 so we address the first byte of the array global (#27566).
        $arrPtr = $context->builder->pointerCast($global, $i8->arrayType(1)->pointerType(0));
        $first = $context->builder->inBoundsGEP(
            $arrPtr,
            $context->getTypeFromString('int64')->constInt(0, false),
            $context->getTypeFromString('int64')->constInt(0, false)
        );

        return $context->builder->pointerCast($first, $i8->pointerType(0));
    }

    private static function lenPtr(Context $context, string $name): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('OutputRewriteVarsStorage missing '.$name);
        }

        return $context->builder->pointerCast($global, $i64->pointerType(0));
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        \PHPCompiler\JIT\LibcExtern::ensureExternalDecl($context, $name, $ft);
    }
}
