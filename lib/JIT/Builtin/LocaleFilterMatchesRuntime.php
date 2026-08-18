<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for locale_filter_matches() via LocaleFilterMatchesJitHelper (#32119).
 *
 * Always {@see JitVmHelperLink} → {@see \PHPCompiler\ext\intl\LocaleFilterMatchesJitHelper}
 * (no user-script NestedJIT defer — thin/user-script AOT must still link the bridge).
 * SSOT: {@see \PHPCompiler\ext\intl\VmLocale::filterMatches()}
 * php-src: ext/intl/locale/locale_methods.c
 */
final class LocaleFilterMatchesRuntime
{
    private const ABI = '__phpc_jit_locale_filter_matches';

    private const HELPER_PATH = '/ext/intl/LocaleFilterMatchesJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\intl\\LocaleFilterMatchesJitHelper::filterMatchesArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [self::HELPER];

    public static function invoke(
        Context $context,
        Value $languageTag,
        Value $locale,
        Value $canonicalize
    ): Value {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $languageTag,
            $locale,
            $canonicalize
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
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'locale_filter_matches_bridge_entry')) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            'locale_filter_matches_bridge_entry',
            [$strPtr, $strPtr, $i64],
            $i1,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#32119'
        );
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
