<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Builtin\ZipArchiveEmbedBridge;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for ZipArchive methods (#35424) — filesystem session keyed by __zipId.
 *
 * php-src: ext/zip/php_zip.c
 */
final class JitZipArchive
{
    public static function invoke(Context $context, string $method, JITVariable ...$args): Value
    {
        ZipArchiveEmbedBridge::ensureLinked($context);

        return match (strtolower($method)) {
            'open' => self::open($context, ...$args),
            'addfromstring' => self::addFromString($context, ...$args),
            'close' => self::close($context, ...$args),
            'getfromname' => self::getFromName($context, ...$args),
            default => throw new \LogicException('ZipArchive::'.$method.' JIT dispatch missing (#35424)'),
        };
    }

    private static function open(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort($context, 'ZipArchive::open() bad argc');

            return $slot;
        }
        $obj = self::objectPtr($context, $args[0]);
        $handle = self::ensureHandle($context, $obj);
        $filename = JitStringBuiltinArg::lower($context, $args[1], 'ZipArchive::open', 0, 'filename');
        $i64 = $context->getTypeFromString('int64');
        $flags = isset($args[2])
            ? JitLongArg::lower($context, $args[2], 'ZipArchive::open(): Argument #2 ($flags)')
            : $i64->constInt(0, false);
        $ok = JitNestedHelperCoerce::extractBoolFromHelperResult(
            $context,
            JitNestedHelperCoerce::callHelper(
                $context,
                ZipArchiveEmbedBridge::open($context),
                [$handle, $filename, $flags]
            )
        );
        self::syncInts($context, $obj, $handle);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $ok);

        return JitValueBox::pointer($context, $slot);
    }

    private static function close(Context $context, JITVariable ...$args): Value
    {
        $obj = self::objectPtr($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $ok = JitNestedHelperCoerce::extractBoolFromHelperResult(
            $context,
            JitNestedHelperCoerce::callHelper($context, ZipArchiveEmbedBridge::close($context), [$handle])
        );
        self::syncInts($context, $obj, $handle);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $ok);

        return JitValueBox::pointer($context, $slot);
    }

    private static function addFromString(Context $context, JITVariable ...$args): Value
    {
        $obj = self::objectPtr($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lower($context, $args[1], 'ZipArchive::addFromString', 0, 'name');
        $content = JitStringBuiltinArg::lower($context, $args[2], 'ZipArchive::addFromString', 1, 'content');
        $ok = JitNestedHelperCoerce::extractBoolFromHelperResult(
            $context,
            JitNestedHelperCoerce::callHelper(
                $context,
                ZipArchiveEmbedBridge::addFromString($context),
                [$handle, $name, $content]
            )
        );
        self::syncInts($context, $obj, $handle);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $ok);

        return JitValueBox::pointer($context, $slot);
    }

    private static function getFromName(Context $context, JITVariable ...$args): Value
    {
        $obj = self::objectPtr($context, $args[0]);
        $handle = self::loadHandle($context, $obj);
        $name = JitStringBuiltinArg::lower($context, $args[1], 'ZipArchive::getFromName', 0, 'name');
        $found = JitNestedHelperCoerce::extractBoolFromHelperResult(
            $context,
            JitNestedHelperCoerce::callHelper(
                $context,
                ZipArchiveEmbedBridge::getFromNameFound($context),
                [$handle, $name]
            )
        );
        $miss = BasicBlockHelper::append($context, 'zip_miss');
        $hit = BasicBlockHelper::append($context, 'zip_hit');
        $done = BasicBlockHelper::append($context, 'zip_done');
        $context->builder->branchIf($found, $hit, $miss);

        $context->builder->positionAtEnd($miss);
        $ms = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $ms, $context->getTypeFromString('int1')->constInt(0, false));
        $mp = JitValueBox::pointer($context, $ms);
        $mt = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($hit);
        $data = JitNestedHelperCoerce::extractStringPtrFromHelperResult(
            $context,
            JitNestedHelperCoerce::callHelper(
                $context,
                ZipArchiveEmbedBridge::getFromNameData($context),
                [$handle, $name]
            )
        );
        $hs = JitValueBox::alloc($context);
        $context->builder->call($context->lookupFunction('__value__writeString'), JitValueBox::pointer($context, $hs), $data);
        $hp = JitValueBox::pointer($context, $hs);
        $ht = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($mp->typeOf());
        $phi->addIncoming($mp, $mt);
        $phi->addIncoming($hp, $ht);

        return $phi;
    }

    private static function ensureHandle(Context $context, Value $obj): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $existing = self::loadHandle($context, $obj);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $existing, $i64->constInt(0, false));
        $allocB = BasicBlockHelper::append($context, 'zip_alloc');
        $haveB = BasicBlockHelper::append($context, 'zip_have');
        $doneB = BasicBlockHelper::append($context, 'zip_hid');
        $context->builder->branchIf($isZero, $allocB, $haveB);

        $context->builder->positionAtEnd($allocB);
        $fresh = JitNestedHelperCoerce::extractLongFromHelperResult(
            $context,
            JitNestedHelperCoerce::callHelper($context, ZipArchiveEmbedBridge::alloc($context), []),
            $i64
        );
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $obj,
            ZipArchiveJitSupport::CLASS_NAME,
            ZipArchiveJitSupport::PROP_ID,
            $fresh
        );
        $at = $context->builder->getInsertBlock();
        $context->builder->branch($doneB);

        $context->builder->positionAtEnd($haveB);
        $ht = $context->builder->getInsertBlock();
        $context->builder->branch($doneB);

        $context->builder->positionAtEnd($doneB);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($fresh, $at);
        $phi->addIncoming($existing, $ht);

        return $phi;
    }

    private static function loadHandle(Context $context, Value $obj): Value
    {
        $v = $context->type->object->propertyFetch(
            $obj,
            ZipArchiveJitSupport::CLASS_NAME,
            ZipArchiveJitSupport::PROP_ID
        );

        return $context->helper->loadValue($v);
    }

    private static function syncInts(Context $context, Value $obj, Value $handle): void
    {
        $i64 = $context->getTypeFromString('int64');
        foreach (
            [
                [ZipArchiveEmbedBridge::propStatus($context), VmZipArchive::PROP_STATUS],
                [ZipArchiveEmbedBridge::propStatusSys($context), VmZipArchive::PROP_STATUS_SYS],
                [ZipArchiveEmbedBridge::propLastId($context), VmZipArchive::PROP_LAST_ID],
                [ZipArchiveEmbedBridge::propNumFiles($context), VmZipArchive::PROP_NUM_FILES],
            ] as [$fn, $prop]
        ) {
            $val = JitNestedHelperCoerce::extractLongFromHelperResult(
                $context,
                JitNestedHelperCoerce::callHelper($context, $fn, [$handle]),
                $i64
            );
            ReflectionSetup::emitSetLongPropertyFromValue(
                $context,
                $obj,
                ZipArchiveJitSupport::CLASS_NAME,
                $prop,
                $val
            );
        }
    }

    private static function objectPtr(Context $context, JITVariable $receiver): Value
    {
        if (JITVariable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (JITVariable::TYPE_VALUE === $receiver->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('ZipArchive method expects object');
    }
}
