<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for Normalizer::normalize() / normalizer_normalize() via NestedJIT helper (#28654).
 *
 * Bundles {@see \PHPCompiler\ext\intl\UnicodeCanonical} with the helper so NestedJIT does not
 * leave UnicodeCanonical::* as ExternalMethod null (#579 / peer StringPack #22842).
 * SSOT sniff: {@see \PHPCompiler\ext\intl\NormalizerNormalizeJitHelper::normalizeArgv}
 * php-src: ext/intl/normalizer/normalizer_normalize.c — PHP_FUNCTION(normalizer_normalize)
 */
final class NormalizerNormalizeRuntime
{
    private const ABI = 'phpc_normalizer_normalize';

    private const HELPER_PATH = '/ext/intl/NormalizerNormalizeJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\intl\\NormalizerNormalizeJitHelper::normalizeArgv';

    private const BRIDGE_ENTRY = 'normalizer_normalize_bridge_entry';

    /** @var list<string> */
    private const HELPER_BUNDLE = [
        '/ext/intl/UnicodeCanonical.php',
        self::HELPER_PATH,
    ];

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function invoke(Context $context, Value $input, Value $form): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $input,
            $form
        );
    }

    public static function implement(Context $context): void
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
        // Bundle UnicodeCanonical before the helper (peer StringPack multi-file NestedJIT).
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            '#28654'
        );
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr, $i64],
            $strPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28654'
        );
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
