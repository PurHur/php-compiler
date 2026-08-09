<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for __compiler_getenv_all (#5075, #20156, #20758, #21579, #24855).
 *
 * Embed + thin standalone AOT: process environ via {@see EnvironMirrorRuntime}
 * (`__superglobals__mirror_process_environ` — shared with $_SERVER refresh #18984).
 * putenv() mirrors into the process environ via `@putenv`/setenv NestedJIT leaf (#29334), so the
 * environ walk already includes overlay entries. A NestedJIT overlay-merge helper
 * segfaults under thin AOT when getenv() is the only env builtin (#24855 / re-#20758).
 * No inline environ-kernel walk in this bridge (Rename #19215 shape).
 * php-src: ext/standard/basic_functions.c — zif_getenv argc==0
 */
final class StringGetenvAll
{
    private const BRIDGE_ENTRY = 'getenv_all_bridge_entry';

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
        // Peer StringGetenv (#26756): empty Type.php declarations are not completed bodies.
        if (NestedJitCompileScope::isActive() && !\PHPCompiler\AOT\HelperRuntimeCache::enabled()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_getenv_all');
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureHashtableHelpers($context);
        // Ensure environ-mirror ABI before emitting getenv_all body (#21579).
        EnvironMirrorRuntime::ensureLinked($context);
        self::implementBridge($context);
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
        $prevActive = $context->activeFunction;
        $context->functions[$abiName] = $fn;
        $context->activeFunction = $abiName;
        try {
            EnvironMirrorRuntime::emitFillCall($context, $ht);
        } finally {
            $context->activeFunction = $prevActive;
        }
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

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_getenv_all');
        if (null === $fn) {
            throw new \LogicException('__compiler_getenv_all missing after StringGetenvAll bridge (#20758)');
        }
        $context->registerFunction('__compiler_getenv_all', $fn);
    }
}
