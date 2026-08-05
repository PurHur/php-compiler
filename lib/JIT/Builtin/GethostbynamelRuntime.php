<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for gethostbynamel() via GethostbynamelJitHelper PHP (#9382, #22397).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer GethostbyaddrRuntime #22370).
 * Replaces glibc struct addrinfo LLVM. SSOT: {@see \PHPCompiler\ext\standard\VmDns}.
 * php-src: ext/standard/dns.c — PHP_FUNCTION(gethostbynamel)
 */
final class GethostbynamelRuntime
{
    private const HELPER_PATH = '/ext/standard/GethostbynamelJitHelper.php';

    private const IP_COUNT_HELPER = 'PHPCompiler\\ext\\standard\\GethostbynamelJitHelper::ipCount';

    private const IP_AT_HELPER = 'PHPCompiler\\ext\\standard\\GethostbynamelJitHelper::ipAt';

    private const ABI_NAME = '__compiler_gethostbynamel';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::IP_COUNT_HELPER,
        self::IP_AT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        // Preserve caller insert block — clearInsertionPosition alone orphans mid-emit (#27406).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureLibc($context);
        self::ensureJitHelperCompiled($context);
        self::implementResolveBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementResolveBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($htPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('ghbl_bridge_entry');
        $emptyBb = $fn->appendBasicBlock('ghbl_bridge_empty');
        $buildInitBb = $fn->appendBasicBlock('ghbl_bridge_build_init');
        $context->builder->positionAtEnd($entry);
        $hostname = $fn->getParam(0);
        $countI64 = $context->builder->call(
            self::helperFunction($context, self::IP_COUNT_HELPER),
            $hostname
        );
        $count = $countI64->typeOf() === $sizeT
            ? $countI64
            : $context->builder->zExt($countI64, $sizeT);
        $hasAny = $context->builder->icmp(
            Builder::INT_SGT,
            $count,
            $sizeT->constInt(0, false)
        );
        $context->builder->branchIf($hasAny, $buildInitBb, $emptyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($buildInitBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $iSlot = $context->builder->alloca($sizeT, 1, 'ghbl_i');
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $loopHead = $fn->appendBasicBlock('ghbl_bridge_loop_head');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $loopDone = $context->builder->icmp(Builder::INT_EQ, $i, $count);
        $loopDoneBb = $fn->appendBasicBlock('ghbl_bridge_loop_done');
        $loopBodyBb = $fn->appendBasicBlock('ghbl_bridge_loop_body');
        $context->builder->branchIf($loopDone, $loopDoneBb, $loopBodyBb);

        $context->builder->positionAtEnd($loopBodyBb);
        $indexI64 = $i->typeOf() === $i64 ? $i : $context->builder->zExt($i, $i64);
        $ipStr = $context->builder->call(
            self::helperFunction($context, self::IP_AT_HELPER),
            $hostname,
            $indexI64
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $ht,
            $indexI64,
            $ipStr
        );
        $context->builder->store(
            $context->builder->add($i, $sizeT->constInt(1, false)),
            $iSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDoneBb);
        $context->builder->returnValue($ht);
        $context->registerFunction(self::ABI_NAME, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22397');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22397'
        );
    }

    private static function ensureLibc(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $voidTy = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        self::ensureExternal(
            $context,
            '__hashtable__alloc',
            $context->context->functionType($htPtr, false)
        );
        self::ensureExternal(
            $context,
            '__hashtable__setStringAt',
            $context->context->functionType($voidTy, false, $htPtr, $i64, $strPtr)
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after GethostbynamelRuntime bridge (#9382)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
