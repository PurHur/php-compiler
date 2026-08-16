<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for readlink() via ReadlinkJitHelper PHP (#15353).
 *
 * Bridge ABI is `__string__*` (null = failure); boxing to string|false happens in the
 * **caller** frame (#28425) — returning a bridge-local {@see JitValueBox::alloc} pointer
 * is use-after-return under AOT (always NULL). Peer: {@see StringNlLanginfo}.
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::readlink()}.
 * php-src: ext/standard/filestat.c — php_readlink
 */
final class StringReadlink
{
    private const ABI = '__phpc_jit_readlink';

    private const HELPER_PATH = '/ext/standard/ReadlinkJitHelper.php';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\ReadlinkJitHelper::resolveArgv';

    private const BRIDGE_ENTRY = 'readlink_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESOLVE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /** @return Value `__value__*` — string or bool false */
    public static function invoke(Context $context, Value $path): Value
    {
        self::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'readlink_invoke_cont');
        $strOrNull = $context->builder->call($context->lookupFunction(self::ABI), $path);

        return self::boxStringOrFalse($context, $strOrNull);
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::RESOLVE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15353'
        );
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /** null `__string__*` → bool false box; else string box (caller-frame allocas). */
    private static function boxStringOrFalse(Context $context, Value $strOrNull): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $strOrNull, $strPtr->constNull());
        $falseBb = BasicBlockHelper::append($context, 'readlink_box_false');
        $strBb = BasicBlockHelper::append($context, 'readlink_box_str');
        $doneBb = BasicBlockHelper::append($context, 'readlink_box_done');
        $context->builder->branchIf($isNull, $falseBb, $strBb);

        $context->builder->positionAtEnd($falseBb);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $falsePtr,
            $i32->constInt(0, false)
        );
        $falseEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($strBb);
        $strSlot = JitValueBox::alloc($context);
        $strVal = JitValueBox::pointer($context, $strSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $strVal,
            $strOrNull
        );
        $strEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($ptrTy);
        $phi->addIncoming($falsePtr, $falseEnd);
        $phi->addIncoming($strVal, $strEnd);

        return $phi;
    }
}
