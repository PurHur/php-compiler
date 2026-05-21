<?php

declare(strict_types=1);

/**
 * Pending response header list for JIT/AOT (issue #311; mirrors {@see \PHPCompiler\Web\ResponseContext}).
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class ResponseHeaders
{
    public const GLOBAL_NAME = '__phpc_pending_headers';

    /** @var Value|null */
    public static $global = null;

    public static function implement(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        self::$global = $context->module->addGlobal($htPtr, self::GLOBAL_NAME);
        self::$global->setInitializer($htPtr->constNull());
    }

    public static function emitResetForStandaloneMain(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType || null === self::$global) {
            return;
        }
        $context->builder->store(
            $context->getTypeFromString('__hashtable__*')->constNull(),
            self::$global
        );
    }

    public static function emitAdd(Context $context, Value $linePtr, Value $replaceI1): void
    {
        $i1 = $context->getTypeFromString('int1');
        $doReplace = $context->builder->icmp(Builder::INT_NE, $replaceI1, $i1->constInt(0, false));
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof Value\Function_);
        $skipRm = $fn->appendBasicBlock('rh_add_skip_rm');
        $doRm = $fn->appendBasicBlock('rh_add_do_rm');
        $afterRm = $fn->appendBasicBlock('rh_add_after_rm');
        $context->builder->branchIf($doReplace, $doRm, $skipRm);
        $context->builder->positionAtEnd($doRm);
        self::emitFilterByName($context, $linePtr);
        $context->builder->branch($afterRm);
        $context->builder->positionAtEnd($skipRm);
        $context->builder->branch($afterRm);
        $context->builder->positionAtEnd($afterRm);
        self::ensureGlobalList($context);
        $ht = $context->builder->load(self::$global);
        ArrayBuiltinHelper::appendElement(
            $context,
            $ht,
            new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $linePtr)
        );
    }

    public static function emitRemove(Context $context, ?Value $namePtr): void
    {
        if (null === $namePtr) {
            $context->builder->store(
                $context->getTypeFromString('__hashtable__*')->constNull(),
                self::$global
            );

            return;
        }
        self::emitFilterByName($context, $namePtr);
    }

    public static function emitList(Context $context): Value
    {
        self::ensureGlobalList($context);
        $src = $context->builder->load(self::$global);
        $sizeT = $context->getTypeFromString('size_t');
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $src
        );
        $dest = HashTableHelper::alloc($context);
        $iSlot = $context->builder->alloca($sizeT, 1, 'rh_list_i');
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $head = BasicBlockHelper::append($context, 'rh_list_head');
        $body = BasicBlockHelper::append($context, 'rh_list_body');
        $done = BasicBlockHelper::append($context, 'rh_list_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $num);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $line = HashTableHelper::readStringAt($context, $src, $i);
        ArrayBuiltinHelper::appendElement(
            $context,
            $dest,
            new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $line)
        );
        $one = $sizeT->constInt(1, false);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $dest;
    }

    /** Drop lines whose header name matches $needle (__string__* header name, not full line). */
    private static function emitFilterByName(Context $context, Value $needle): void
    {
        $src = $context->builder->load(self::$global);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $src, $htPtr->constNull());
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof Value\Function_);
        $empty = $fn->appendBasicBlock('rh_filt_empty');
        $work = $fn->appendBasicBlock('rh_filt_work');
        $merge = $fn->appendBasicBlock('rh_filt_merge');
        $context->builder->branchIf($isNull, $empty, $work);

        $context->builder->positionAtEnd($empty);
        $context->builder->store(HashTableHelper::alloc($context), self::$global);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($work);
        $src = $context->builder->load(self::$global);
        $sizeT = $context->getTypeFromString('size_t');
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $src
        );
        $dest = HashTableHelper::alloc($context);
        $iSlot = $context->builder->alloca($sizeT, 1, 'rh_filt_i');
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $head = BasicBlockHelper::append($context, 'rh_filt_head');
        $body = BasicBlockHelper::append($context, 'rh_filt_body');
        $keep = BasicBlockHelper::append($context, 'rh_filt_keep');
        $next = BasicBlockHelper::append($context, 'rh_filt_next');
        $workDone = BasicBlockHelper::append($context, 'rh_filt_work_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $num);
        $context->builder->branchIf($atEnd, $workDone, $body);

        $context->builder->positionAtEnd($body);
        $line = HashTableHelper::readStringAt($context, $src, $i);
        $drop = self::emitLineNameMatchesNeedle($context, $line, $needle);
        $context->builder->branchIf($drop, $next, $keep);

        $context->builder->positionAtEnd($keep);
        ArrayBuiltinHelper::appendElement(
            $context,
            $dest,
            new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $line)
        );
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $one = $sizeT->constInt(1, false);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($workDone);
        $context->builder->store($dest, self::$global);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
    }

    /** @return Value int1 true when the line should be dropped */
    private static function emitLineNameMatchesNeedle(Context $context, Value $line, Value $needle): Value
    {
        $strMap = $context->structFieldMap['__string__'];
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i32->constInt(0, false);
        $colonByte = $i32->constInt(ord(':'), false);

        $lineLen = $context->builder->load($context->builder->structGep($line, $strMap['length']));
        $lineData = $context->builder->structGep($line, $strMap['value']);
        $needleLen = $context->builder->load($context->builder->structGep($needle, $strMap['length']));
        $needleData = $context->builder->structGep($needle, $strMap['value']);

        $lineNameLen = self::emitHeaderNameLength($context, $lineData, $lineLen, $colonByte);
        $needleNameLen = self::emitHeaderNameLength($context, $needleData, $needleLen, $colonByte);
        $lenEq = $context->builder->icmp(Builder::INT_EQ, $lineNameLen, $needleNameLen);
        $cmp = $context->builder->call(
            $context->lookupFunction('strncasecmp'),
            $lineData,
            $needleData,
            $context->builder->trunc($lineNameLen, $sizeT)
        );
        $nameMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $zero);

        return $context->builder->and($lenEq, $nameMatch);
    }

    private static function emitHeaderNameLength(
        Context $context,
        Value $data,
        Value $len,
        Value $colonByte
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $colon = $context->builder->call(
            $context->lookupFunction('memchr'),
            $data,
            $colonByte,
            $context->builder->trunc($len, $sizeT)
        );
        $fullLen = $context->builder->zext($len, $i64);

        return $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $colon, $data->typeOf()->constNull()),
            $fullLen,
            $context->builder->sub(
                $context->builder->ptrToInt($colon, $i64),
                $context->builder->ptrToInt($data, $i64)
            )
        );
    }

    private static function ensureGlobalList(Context $context): void
    {
        $cur = $context->builder->load(self::$global);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $cur, $htPtr->constNull());
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof Value\Function_);
        $empty = $fn->appendBasicBlock('rh_ensure_empty');
        $done = $fn->appendBasicBlock('rh_ensure_done');
        $context->builder->branchIf($isNull, $empty, $done);

        $context->builder->positionAtEnd($empty);
        $context->builder->store(HashTableHelper::alloc($context), self::$global);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }
}
