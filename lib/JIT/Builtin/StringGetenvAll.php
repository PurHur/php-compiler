<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_getenv_all via GetenvJitHelper PHP (#5075, #20156).
 *
 * Embed / non-thin: {@see GetenvJitHelper::fillAllEnvironmentHashtable} via {@see JitVmHelperLink}.
 * Thin standalone AOT: linkable void stub (inventory / user-script init — #14470 / #16075).
 * php-src: ext/standard/basic_functions.c — zif_getenv argc==0
 */
final class StringGetenvAll
{
    private const HELPER_PATH = '/ext/standard/GetenvJitHelper.php';

    private const GETENV_ALL_HELPER = 'PHPCompiler\\ext\\standard\\GetenvJitHelper::fillAllEnvironmentHashtable';

    private const BRIDGE_ENTRY = 'getenv_all_bridge_entry';

    private const THIN_STUB_ENTRY = 'getenv_all_thin_stub';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GETENV_ALL_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_getenv_all');
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)
            || JitVmHelperLink::hasNamedBridgeEntry($probe, self::THIN_STUB_ENTRY)) {
            self::registerLinkedRuntime($context);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        if ($context->isThinStandaloneAotMain()) {
            self::implementThinStub($context, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureHashtableHelpers($context);
        self::ensureJitHelperCompiled($context);
        self::implementBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            $context->builder->positionAtEnd($savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementThinStub(Context $context, ?LlvmFunction $probe): void
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($voidTy, false, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_getenv_all', $ft);
        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::THIN_STUB_ENTRY);
        $context->builder->positionAtEnd($entry);
        $context->builder->returnVoid();
        $context->registerFunction('__compiler_getenv_all', $fn);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            $context->builder->positionAtEnd($savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $abiName = '__compiler_getenv_all';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($voidTy, false, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $nullOut = $context->builder->icmp(Builder::INT_EQ, $out, $valuePtr->constNull());
        $nullOutBb = $fn->appendBasicBlock('getenv_all_null_out');
        $bodyBb = $fn->appendBasicBlock('getenv_all_body');
        $context->builder->branchIf($nullOut, $nullOutBb, $bodyBb);

        $context->builder->positionAtEnd($nullOutBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bodyBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());
        $allocFailBb = $fn->appendBasicBlock('getenv_all_alloc_fail');
        $fillBb = $fn->appendBasicBlock('getenv_all_fill');
        $context->builder->branchIf($htNull, $allocFailBb, $fillBb);

        $context->builder->positionAtEnd($allocFailBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($fillBb);
        JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::GETENV_ALL_HELPER),
            [$ht]
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $out,
            $ht
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__value__writeHashtable', $voidTy, [$valuePtr, $htPtr]],
        ] as [$name, $ret, $params]) {
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

    private static function ensureJitHelperCompiled(Context $context): void
    {
        StringGetenv::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20156'
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#20156');
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_getenv_all');
        if (null === $fn) {
            throw new \LogicException('__compiler_getenv_all missing after StringGetenvAll bridge (#20156)');
        }
        $context->registerFunction('__compiler_getenv_all', $fn);
    }
}
