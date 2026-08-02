<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for __compiler_soundex via SoundexJitHelper PHP (#13448, #21362, #26882).
 *
 * User-script AOT uses HelperRuntimeCache prelinked units (#15889). Peer: StringStrRot13 #26868.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString} (VM); helper is NestedJIT-self-contained.
 * php-src: ext/standard/string.c — PHP_FUNCTION(soundex)
 */
final class StringSoundex
{
    private const ABI = '__compiler_soundex';

    private const HELPER_PATH = '/ext/standard/SoundexJitHelper.php';

    private const SOUNDEX_HELPER = 'PHPCompiler\\ext\\standard\\SoundexJitHelper::soundexArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SOUNDEX_HELPER,
    ];

    private const BRIDGE_ENTRY = 'soundex_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
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
        self::implementBridge($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::SOUNDEX_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26882'
        );
    }
}
