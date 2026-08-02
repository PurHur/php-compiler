<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_metaphone via MetaphoneJitHelper PHP (#13447, #21342).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureBridge} (HelperRuntimeCache + user-script
 * env clear — no hand-rolled NestedJit compile loop). Peer: StringChdir #21147 / TimeSleep #21289.
 * SSOT: {@see \PHPCompiler\ext\standard\VmMetaphone}.
 * php-src: ext/standard/metaphone.c — PHP_FUNCTION(metaphone)
 */
final class StringMetaphone
{
    private const ABI = '__compiler_metaphone';

    private const HELPER_PATH = '/ext/standard/MetaphoneJitHelper.php';

    /**
     * NestedJIT VmMetaphone with the helper — solo MetaphoneJitHelper stubs
     * VmMetaphone::encode (fingerprint deps are not NestedJIT'd) (#26794).
     *
     * @var list<string>
     */
    private const HELPER_BUNDLE = [
        '/ext/standard/VmMetaphone.php',
        '/ext/standard/MetaphoneJitHelper.php',
    ];

    private const METAPHONE_HELPER = 'PHPCompiler\\ext\\standard\\MetaphoneJitHelper::metaphoneArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::METAPHONE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'metaphone_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
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
        $i64 = $context->getTypeFromString('int64');
        // Bundle NestedJITs VmMetaphone before the ABI bridge (#26794).
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            '#21342'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $i64],
            $strPtr,
            self::METAPHONE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21342'
        );
    }
}
