<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitGethostnameKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for gethostname() via GethostnameJitHelper PHP (#21166, #29364).
 *
 * Embed + thin standalone AOT: {@see GethostnameJitHelper} via {@see JitVmHelperLink}
 * (chdir #21147 / getenv #20644 shape — no thin libc ABI fork).
 * Nested helper compile: `@gethostname` → /proc+/etc leaf ({@see JitGethostnameKernel})
 * without re-entering GethostnameJitHelper — no libc gethostname(2) (#28544); kernel
 * Internal deleted (#29364, peer getenv #29313 / putenv #29334).
 * SSOT for VM: {@see \PHPCompiler\ext\standard\VmHost::gethostname()} / {@see VmHostPure}.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(gethostname)
 */
final class StringGethostname
{
    private const ABI = '__phpc_jit_gethostname';

    private const HELPER_PATH = '/ext/standard/GethostnameJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\GethostnameJitHelper::resolveJit';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'gethostname_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /** @return Value `__string__*` — empty string when hostname unavailable */
    public static function invoke(Context $context): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitGethostnameKernel::invoke($context);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI));
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
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [],
            $strPtr,
            self::INVOKE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21166'
        );
    }
}
