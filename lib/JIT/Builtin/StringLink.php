<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for link() via LinkJitHelper PHP (#15544, #33406).
 *
 * User-script AOT: {@see LinkJitHelper} via {@see JitVmHelperLink}
 * (Rename #29141 shape — helper SSOT is {@see \PHPCompiler\ext\standard\VmFs::hardLink()}).
 * NestedJIT leaf: module-local link(2) decl (avoids re-entering the helper
 * bridge while NestedJIT compiles LinkJitHelper / `@link`).
 * Replaces libc linkat(2) LLVM in ext/standard/JitLink.php.
 * php-src: ext/standard/link.c — php_link
 */
final class StringLink
{
    private const ABI = '__phpc_jit_link';

    private const HELPER_PATH = '/ext/standard/LinkJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\LinkJitHelper::invokeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'link_bridge_entry';

    /** Module-local link(2) — NestedJIT leaf (#33406 / peer rename #29141). */
    private const COMPILER_LINK = '__compiler_link';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $target, Value $link): Value
    {
        // Nested helper compile: libc leaf without re-entering LinkJitHelper (#33406).
        if (NestedJitCompileScope::isActive()) {
            return self::invokeNestedLeaf($context, $target, $link);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $target, $link);
    }

    /** @return Value i1 — true when link(2) returns 0 */
    public static function invokeNestedLeaf(Context $context, Value $targetStr, Value $linkStr): Value
    {
        return self::invokeCompilerLink($context, $targetStr, $linkStr);
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

        $strPtr = $context->getTypeFromString('__string__*');
        // ABI stays i1 for link() callers; helper returns int 0/1 so NestedJIT
        // uses readLong (bool boxes have no readLong arm — always 0; #20603 / #29141).
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $strPtr],
            $i1,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#33406'
        );
    }

    /** @return Value i1 — true when link(2) returns 0 */
    private static function invokeCompilerLink(Context $context, Value $targetStr, Value $linkStr): Value
    {
        self::ensureCompilerLinkDecl($context);
        $map = $context->structFieldMap['__string__'];
        $targetPtr = $context->builder->structGep($targetStr, $map['value']);
        $linkPtr = $context->builder->structGep($linkStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction(self::COMPILER_LINK),
            $targetPtr,
            $linkPtr
        );
        $zero = $i32->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
    }

    /**
     * Declare link(2) under a compiler-owned alias (peer {@see StringRename} #29090).
     * The linker resolves the body from libc via the {@code link} symbol name.
     */
    private static function ensureCompilerLinkDecl(Context $context): void
    {
        try {
            $context->lookupFunction(self::COMPILER_LINK);

            return;
        } catch (\Throwable $e) {
        }
        try {
            $existing = $context->lookupFunction('link');
            $context->registerFunction(self::COMPILER_LINK, $existing);

            return;
        } catch (\Throwable $e) {
        }
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p, $i8p);
        $fn = $context->module->addFunction('link', $ft);
        $context->registerFunction('link', $fn);
        $context->registerFunction(self::COMPILER_LINK, $fn);
    }
}
