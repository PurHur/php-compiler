<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM json_encode string quoting — bypasses NestedJIT __string__* mis-read (#26367, #32897).
 *
 * Peer {@see SprintfSnprintfRuntime} / {@see StringStrcoll}: thin AOT keeps string
 * work on {@see __string__*} / i8* ABIs, not NestedJIT PHP string params.
 * Module-local ABI owner for {@see self::ABI} (getNamedFunction first): Builtin\Type no
 * longer always-declares an empty shell (#32897 / peer #32893).
 * php-src: ext/json/php_json.c — json_escape_string
 */
final class JsonEncodeQuoteStringRuntime
{
    public const ABI = '__compiler_json_quote_string';

    private const ENTRY = 'json_quote_string_entry';

    /** Worst-case expansion: every byte → \ + char, plus two quote bytes. */
    private const EXPAND_FACTOR = 2;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function quote(Context $context, Value $strSep): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $strSep);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        SprintfSnprintfRuntime::ensureDeclsPublic($context);
        LibcExtern::ensureMemcpyImplemented($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::ABI, $ft);
        // Register before body emit — Type empty shells used to pre-register (#32897).
        $context->registerFunction(self::ABI, $fn);
        $entry = $fn->appendBasicBlock(self::ENTRY);
        $context->builder->positionAtEnd($entry);

        $in = $fn->getParam(0);
        $b = $context->builder;
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $map = $context->structFieldMap['__string__'];

        $len = $b->call($context->lookupFunction('__string__strlen'), $in);
        $src = $b->pointerCast($b->structGep($in, $map['value']), $i8p);
        $cap = $b->add(
            $b->mul($len, $i64->constInt(self::EXPAND_FACTOR + 1, false)),
            $i64->constInt(3, false)
        );
        $outBuf = $b->call($context->lookupFunction('__mm__malloc'), $b->trunc($cap, $sizeT));
        $out = $b->pointerCast($outBuf, $i8p);

        $idxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $b->store($i64->constInt(0, false), $idxSlot);
        $b->store($i64->constInt(0, false), $iSlot);

        $writeOpen = $fn->appendBasicBlock('json_quote_open');
        $loopHead = $fn->appendBasicBlock('json_quote_loop');
        $loopBody = $fn->appendBasicBlock('json_quote_body');
        $loopNext = $fn->appendBasicBlock('json_quote_next');
        $loopDone = $fn->appendBasicBlock('json_quote_done');
        $writeClose = $fn->appendBasicBlock('json_quote_close');
        $b->branch($writeOpen);

        $b->positionAtEnd($writeOpen);
        self::storeByte($context, $b, $out, $idxSlot, $i8->constInt(34, false));
        $b->branch($loopHead);

        $b->positionAtEnd($loopHead);
        $i = $b->load($iSlot);
        $cont = $b->icmp(Builder::INT_ULT, $i, $len);
        $b->branchIf($cont, $loopBody, $loopDone);

        $b->positionAtEnd($loopBody);
        $ch = $b->load($b->gep($src, $i));
        self::emitEscapedChar($context, $fn, $b, $out, $idxSlot, $ch);
        $b->branch($loopNext);

        $b->positionAtEnd($loopNext);
        $b->store($b->add($i, $i64->constInt(1, false)), $iSlot);
        $b->branch($loopHead);

        $b->positionAtEnd($loopDone);
        self::storeByte($context, $b, $out, $idxSlot, $i8->constInt(34, false));
        $b->branch($writeClose);

        $b->positionAtEnd($writeClose);
        $outLen = $b->load($idxSlot);
        $result = $b->call(
            $context->lookupFunction('__string__init'),
            $outLen,
            $out
        );
        $b->call($context->lookupFunction('__mm__free'), $outBuf);
        $b->returnValue($result);
        $context->registerFunction(self::ABI, $fn);
        BasicBlockHelper::restoreInsertBlock($context, $saved);
    }

    private static function storeByte(
        Context $context,
        \PHPLLVM\Builder $b,
        Value $out,
        Value $idxSlot,
        Value $byte
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $idx = $b->load($idxSlot);
        $b->store($byte, $b->gep($out, $idx));
        $b->store($b->add($idx, $i64->constInt(1, false)), $idxSlot);
    }

    private static function emitEscapedChar(
        Context $context,
        LlvmFunction $fn,
        \PHPLLVM\Builder $b,
        Value $out,
        Value $idxSlot,
        Value $ch
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $esc = $fn->appendBasicBlock('json_quote_esc');
        $plain = $fn->appendBasicBlock('json_quote_plain');
        $done = $fn->appendBasicBlock('json_quote_esc_done');

        $isSpecial = $b->or(
            $b->or(
                $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(92, false)),
                $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(34, false))
            ),
            $b->or(
                $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(47, false)),
                $b->or(
                    $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(10, false)),
                    $b->or(
                        $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(13, false)),
                        $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(9, false))
                    )
                )
            )
        );
        $b->branchIf($isSpecial, $esc, $plain);

        $b->positionAtEnd($esc);
        self::storeByte($context, $b, $out, $idxSlot, $i8->constInt(92, false));
        $escaped = $b->select(
            $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(10, false)),
            $i8->constInt(110, false),
            $b->select(
                $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(13, false)),
                $i8->constInt(114, false),
                $b->select(
                    $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(9, false)),
                    $i8->constInt(116, false),
                    $ch
                )
            )
        );
        self::storeByte($context, $b, $out, $idxSlot, $escaped);
        $escEnd = $b->getInsertBlock();
        $b->branch($done);

        $b->positionAtEnd($plain);
        self::storeByte($context, $b, $out, $idxSlot, $ch);
        $plainEnd = $b->getInsertBlock();
        $b->branch($done);

        $b->positionAtEnd($done);
    }
}
