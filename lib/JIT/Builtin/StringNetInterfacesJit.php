<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\NetInterfacesJitHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_net_get_interfaces via NetInterfacesJitHelper PHP (#8988, #23715, #26942).
 *
 * Thin AOT: NestedJIT HashTable return is not a real `__hashtable__*` — materialize via
 * `__hashtable__alloc` + setStringKey* from NestedJIT scalars (peer sys_getloadavg #27294 /
 * gethostbynamel #22397).
 *
 * SSOT: VmNetInterfaces::get. php-src: ext/standard/net.c
 * Helper compile: {@see JitVmHelperLink::ensureCompiled}.
 */
final class StringNetInterfacesJit
{
    private const ABI_NAME = '__compiler_net_get_interfaces';

    private const HELPER_PATH = '/ext/standard/NetInterfacesJitHelper.php';

    private const RESOLVE_OK = 'PHPCompiler\\ext\\standard\\NetInterfacesJitHelper::resolveOk';

    private const IFACE_COUNT = 'PHPCompiler\\ext\\standard\\NetInterfacesJitHelper::ifaceCount';

    private const IFACE_NAME = 'PHPCompiler\\ext\\standard\\NetInterfacesJitHelper::ifaceNameAt';

    private const IFACE_UP = 'PHPCompiler\\ext\\standard\\NetInterfacesJitHelper::ifaceUpAt';

    private const UNICAST_COUNT = 'PHPCompiler\\ext\\standard\\NetInterfacesJitHelper::unicastCountAt';

    private const UNICAST_MASK = 'PHPCompiler\\ext\\standard\\NetInterfacesJitHelper::unicastMaskAt';

    private const UNICAST_FLAGS = 'PHPCompiler\\ext\\standard\\NetInterfacesJitHelper::unicastFlagsAt';

    private const UNICAST_FAMILY = 'PHPCompiler\\ext\\standard\\NetInterfacesJitHelper::unicastFamilyAt';

    private const UNICAST_ADDRESS = 'PHPCompiler\\ext\\standard\\NetInterfacesJitHelper::unicastAddressAt';

    private const UNICAST_NETMASK = 'PHPCompiler\\ext\\standard\\NetInterfacesJitHelper::unicastNetmaskAt';

    private const UNICAST_BROADCAST = 'PHPCompiler\\ext\\standard\\NetInterfacesJitHelper::unicastBroadcastAt';

    private const UNICAST_PTP = 'PHPCompiler\\ext\\standard\\NetInterfacesJitHelper::unicastPtpAt';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESOLVE_OK,
        self::IFACE_COUNT,
        self::IFACE_NAME,
        self::IFACE_UP,
        self::UNICAST_COUNT,
        self::UNICAST_MASK,
        self::UNICAST_FLAGS,
        self::UNICAST_FAMILY,
        self::UNICAST_ADDRESS,
        self::UNICAST_NETMASK,
        self::UNICAST_BROADCAST,
        self::UNICAST_PTP,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            self::declareAbiForNestedJit($context);

            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Restore caller insert block after bridge emit (#20988 / peer StrRepeat #19998).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureExternals($context);
        self::ensureJitHelperCompiled($context);
        self::implementBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function declareAbiForNestedJit(Context $context): void
    {
        try {
            $context->lookupFunction(self::ABI_NAME);
        } catch (\Throwable) {
            $voidTy = $context->getTypeFromString('void');
            $valuePtr = $context->getTypeFromString('__value__*');
            $context->registerFunction(
                self::ABI_NAME,
                $context->module->addFunction(
                    self::ABI_NAME,
                    $context->context->functionType($voidTy, false, $valuePtr)
                )
            );
        }
    }

    private static function implementBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($voidTy, false, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('ngi_bridge_entry');
        $nullOutBb = $fn->appendBasicBlock('ngi_bridge_null_out');
        $bodyBb = $fn->appendBasicBlock('ngi_bridge_body');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $context->builder->branchIf($outNull, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $okWide = $context->builder->call(self::helperFunction($context, self::RESOLVE_OK));
        $ok = $context->builder->trunc($okWide, $i32);
        $okFlag = $context->builder->icmp(Builder::INT_NE, $ok, $i32->constInt(0, false));
        $failBb = $fn->appendBasicBlock('ngi_bridge_fail');
        $okBb = $fn->appendBasicBlock('ngi_bridge_ok');
        $context->builder->branchIf($okFlag, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($okBb);
        $root = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $countWide = $context->builder->call(self::helperFunction($context, self::IFACE_COUNT));
        $count = $countWide->typeOf() === $sizeT
            ? $countWide
            : $context->builder->zExt($countWide, $sizeT);
        $iSlot = $context->builder->alloca($sizeT, 1, 'ngi_i');
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $ifaceHead = $fn->appendBasicBlock('ngi_iface_head');
        $context->builder->branch($ifaceHead);

        $context->builder->positionAtEnd($ifaceHead);
        $i = $context->builder->load($iSlot);
        $ifaceDone = $context->builder->icmp(Builder::INT_EQ, $i, $count);
        $ifaceDoneBb = $fn->appendBasicBlock('ngi_iface_done');
        $ifaceBodyBb = $fn->appendBasicBlock('ngi_iface_body');
        $context->builder->branchIf($ifaceDone, $ifaceDoneBb, $ifaceBodyBb);

        $context->builder->positionAtEnd($ifaceBodyBb);
        $i64Idx = $i->typeOf() === $i64 ? $i : $context->builder->zExt($i, $i64);
        $nameStr = $context->builder->call(
            self::helperFunction($context, self::IFACE_NAME),
            $i64Idx
        );
        $upWide = $context->builder->call(
            self::helperFunction($context, self::IFACE_UP),
            $i64Idx
        );
        $up = $context->builder->trunc($upWide, $i1);
        $ifaceHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyBool'),
            $ifaceHt,
            self::literalKeyString($context, 'up'),
            $up
        );

        $uCountWide = $context->builder->call(
            self::helperFunction($context, self::UNICAST_COUNT),
            $i64Idx
        );
        $uCount = $uCountWide->typeOf() === $sizeT
            ? $uCountWide
            : $context->builder->zExt($uCountWide, $sizeT);
        $unicastHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $uSlot = $context->builder->alloca($sizeT, 1, 'ngi_u');
        $context->builder->store($sizeT->constInt(0, false), $uSlot);
        $uHead = $fn->appendBasicBlock('ngi_u_head');
        $context->builder->branch($uHead);

        $context->builder->positionAtEnd($uHead);
        $u = $context->builder->load($uSlot);
        $uDone = $context->builder->icmp(Builder::INT_EQ, $u, $uCount);
        $uDoneBb = $fn->appendBasicBlock('ngi_u_done');
        $uBodyBb = $fn->appendBasicBlock('ngi_u_body');
        $context->builder->branchIf($uDone, $uDoneBb, $uBodyBb);

        $context->builder->positionAtEnd($uBodyBb);
        $u64 = $u->typeOf() === $i64 ? $u : $context->builder->zExt($u, $i64);
        $entryHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $maskWide = $context->builder->call(
            self::helperFunction($context, self::UNICAST_MASK),
            $i64Idx,
            $u64
        );
        $mask = $maskWide->typeOf() === $i64
            ? $maskWide
            : $context->builder->sext($maskWide, $i64);

        self::emitOptionalLong(
            $context,
            $fn,
            $entryHt,
            $mask,
            NetInterfacesJitHelper::HAS_FLAGS,
            'flags',
            self::UNICAST_FLAGS,
            $i64Idx,
            $u64
        );
        self::emitOptionalLong(
            $context,
            $fn,
            $entryHt,
            $mask,
            NetInterfacesJitHelper::HAS_FAMILY,
            'family',
            self::UNICAST_FAMILY,
            $i64Idx,
            $u64
        );
        self::emitOptionalString(
            $context,
            $fn,
            $entryHt,
            $mask,
            NetInterfacesJitHelper::HAS_ADDRESS,
            'address',
            self::UNICAST_ADDRESS,
            $i64Idx,
            $u64
        );
        self::emitOptionalString(
            $context,
            $fn,
            $entryHt,
            $mask,
            NetInterfacesJitHelper::HAS_NETMASK,
            'netmask',
            self::UNICAST_NETMASK,
            $i64Idx,
            $u64
        );
        self::emitOptionalString(
            $context,
            $fn,
            $entryHt,
            $mask,
            NetInterfacesJitHelper::HAS_BROADCAST,
            'broadcast',
            self::UNICAST_BROADCAST,
            $i64Idx,
            $u64
        );
        self::emitOptionalString(
            $context,
            $fn,
            $entryHt,
            $mask,
            NetInterfacesJitHelper::HAS_PTP,
            'ptp',
            self::UNICAST_PTP,
            $i64Idx,
            $u64
        );

        // Continue after optional emits — last emit leaves builder at the merge of ptp.
        $context->builder->call(
            $context->lookupFunction('__hashtable__setHashtableAt'),
            $unicastHt,
            $u64,
            $entryHt
        );
        $context->builder->store(
            $context->builder->add($u, $sizeT->constInt(1, false)),
            $uSlot
        );
        $context->builder->branch($uHead);

        $context->builder->positionAtEnd($uDoneBb);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $ifaceHt,
            self::literalKeyString($context, 'unicast'),
            $unicastHt
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $root,
            $nameStr,
            $ifaceHt
        );
        $context->builder->store(
            $context->builder->add($i, $sizeT->constInt(1, false)),
            $iSlot
        );
        $context->builder->branch($ifaceHead);

        $context->builder->positionAtEnd($ifaceDoneBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $out,
            $root
        );
        $context->builder->returnVoid();
        $context->registerFunction(self::ABI_NAME, $fn);
        $context->builder->clearInsertionPosition();
    }

    /**
     * Emit optional long field: if (mask & bit) setStringKeyLong.
     * Leaves the builder positioned at the join block after the optional write.
     */
    private static function emitOptionalLong(
        Context $context,
        LlvmFunction $fn,
        Value $entryHt,
        Value $mask,
        int $bit,
        string $key,
        string $helperLogical,
        Value $ifaceIdx,
        Value $uIdx
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $hasBb = $fn->appendBasicBlock('ngi_has_'.$key);
        $joinBb = $fn->appendBasicBlock('ngi_join_'.$key);
        $masked = $context->builder->and($mask, $i64->constInt($bit, false));
        $has = $context->builder->icmp(Builder::INT_NE, $masked, $i64->constInt(0, false));
        $context->builder->branchIf($has, $hasBb, $joinBb);

        $context->builder->positionAtEnd($hasBb);
        $valWide = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            $ifaceIdx,
            $uIdx
        );
        $val = $valWide->typeOf() === $i64
            ? $valWide
            : $context->builder->sext($valWide, $i64);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $entryHt,
            self::literalKeyString($context, $key),
            $val
        );
        $context->builder->branch($joinBb);
        $context->builder->positionAtEnd($joinBb);
    }

    private static function emitOptionalString(
        Context $context,
        LlvmFunction $fn,
        Value $entryHt,
        Value $mask,
        int $bit,
        string $key,
        string $helperLogical,
        Value $ifaceIdx,
        Value $uIdx
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $hasBb = $fn->appendBasicBlock('ngi_has_'.$key);
        $joinBb = $fn->appendBasicBlock('ngi_join_'.$key);
        $masked = $context->builder->and($mask, $i64->constInt($bit, false));
        $has = $context->builder->icmp(Builder::INT_NE, $masked, $i64->constInt(0, false));
        $context->builder->branchIf($has, $hasBb, $joinBb);

        $context->builder->positionAtEnd($hasBb);
        $val = $context->builder->call(
            self::helperFunction($context, $helperLogical),
            $ifaceIdx,
            $uIdx
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $entryHt,
            self::literalKeyString($context, $key),
            $val
        );
        $context->builder->branch($joinBb);
        $context->builder->positionAtEnd($joinBb);
    }

    private static function literalKeyString(Context $context, string $key): Value
    {
        // Peer JitStreamContextThinAot::literalString — stable __string__* globals (#26942).
        return $context->builder->load($context->constantStringFromString($key));
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#26942');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26942'
        );
    }

    private static function ensureExternals(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $i64 = $context->getTypeFromString('int64');

        foreach ([
            ['__value__writeBool', $voidTy, [$valuePtr, $i32]],
            ['__value__writeHashtable', $voidTy, [$valuePtr, $htPtr]],
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setStringKeyBool', $voidTy, [$htPtr, $strPtr, $i1]],
            ['__hashtable__setStringKeyLong', $voidTy, [$htPtr, $strPtr, $i64]],
            ['__hashtable__setStringKeyString', $voidTy, [$htPtr, $strPtr, $strPtr]],
            ['__hashtable__setStringKeyHashtable', $voidTy, [$htPtr, $strPtr, $htPtr]],
            ['__hashtable__setHashtableAt', $voidTy, [$htPtr, $i64, $htPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal(
                $context,
                $name,
                $context->context->functionType($ret, false, ...$params)
            );
        }
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

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after StringNetInterfacesJit bridge (#26942)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
