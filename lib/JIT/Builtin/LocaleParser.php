<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for locale_get_primary_language/region/script via LocaleParserJitHelper PHP (#17072, #20101).
 *
 * Always {@see JitVmHelperLink} → {@see \PHPCompiler\ext\intl\LocaleParserJitHelper}
 * (no user-script NestedJIT defer early-return — thin/user-script AOT must still link bridges).
 * SSOT: {@see \PHPCompiler\ext\intl\VmLocale}
 * php-src: ext/intl/locale/locale_methods.c
 */
final class LocaleParser
{
    private const ABI_PRIMARY = '__phpc_jit_locale_get_primary_language';

    private const ABI_REGION = '__phpc_jit_locale_get_region';

    private const ABI_SCRIPT = '__phpc_jit_locale_get_script';

    private const HELPER_PATH = '/ext/intl/LocaleParserJitHelper.php';

    private const PRIMARY_HELPER = 'PHPCompiler\\ext\\intl\\LocaleParserJitHelper::primaryLanguageArgv';

    private const REGION_HELPER = 'PHPCompiler\\ext\\intl\\LocaleParserJitHelper::regionArgv';

    private const SCRIPT_HELPER = 'PHPCompiler\\ext\\intl\\LocaleParserJitHelper::scriptArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PRIMARY_HELPER,
        self::REGION_HELPER,
        self::SCRIPT_HELPER,
    ];

    public static function invokePrimaryLanguage(Context $context, Value $locale): Value
    {
        self::ensurePrimaryLanguageLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_PRIMARY),
            $locale
        );
    }

    public static function invokeRegion(Context $context, Value $locale): Value
    {
        self::ensureRegionLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_REGION),
            $locale
        );
    }

    public static function invokeScript(Context $context, Value $locale): Value
    {
        self::ensureScriptLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_SCRIPT),
            $locale
        );
    }

    public static function ensurePrimaryLanguageLinked(Context $context): void
    {
        self::ensureBridge(
            $context,
            self::ABI_PRIMARY,
            'locale_get_primary_language_bridge_entry',
            self::PRIMARY_HELPER
        );
    }

    public static function ensureRegionLinked(Context $context): void
    {
        self::ensureBridge(
            $context,
            self::ABI_REGION,
            'locale_get_region_bridge_entry',
            self::REGION_HELPER
        );
    }

    public static function ensureScriptLinked(Context $context): void
    {
        self::ensureBridge(
            $context,
            self::ABI_SCRIPT,
            'locale_get_script_bridge_entry',
            self::SCRIPT_HELPER
        );
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensurePrimaryLanguageLinked($context);
        self::ensureRegionLinked($context);
        self::ensureScriptLinked($context);
    }

    private static function ensureBridge(
        Context $context,
        string $abi,
        string $entry,
        string $helper
    ): void {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entry)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            $abi,
            $entry,
            [$strPtr],
            $strPtr,
            $helper,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20101'
        );
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
