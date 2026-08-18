<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for locale_lookup() via LocaleLookupJitHelper (#32118).
 *
 * Always {@see JitVmHelperLink} → {@see \PHPCompiler\ext\intl\LocaleLookupJitHelper}
 * (no user-script NestedJIT defer — thin/user-script AOT must still link the bridge).
 * SSOT: {@see \PHPCompiler\ext\intl\VmLocale::lookup()}
 * php-src: ext/intl/locale/locale_methods.c
 */
final class LocaleLookupRuntime
{
    private const ABI = '__phpc_jit_locale_lookup';

    private const HELPER_PATH = '/ext/intl/LocaleLookupJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\intl\\LocaleLookupJitHelper::lookupArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [self::HELPER];

    public static function invoke(
        Context $context,
        Value $languageTag,
        Value $locale,
        Value $canonicalize,
        Value $defaultLocale,
        Value $hasDefault
    ): Value {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $languageTag,
            $locale,
            $canonicalize,
            $defaultLocale,
            $hasDefault
        );
    }

    public static function ensureLinked(Context $context): void
    {
        self::ensureBridge($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function ensureBridge(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'locale_lookup_bridge_entry')) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'locale_lookup_bridge_entry',
            [$htPtr, $strPtr, $i64, $strPtr, $i64],
            $strPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#32118'
        );
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
