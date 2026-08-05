<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for locale_get_primary_language/region/script + canonicalize + get_default via LocaleParserJitHelper
 * (#17072, #20101, #20760, #27369).
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

    private const ABI_CANONICALIZE = '__phpc_jit_locale_canonicalize';

    private const ABI_DEFAULT = '__phpc_jit_locale_get_default';

    private const HELPER_PATH = '/ext/intl/LocaleParserJitHelper.php';

    private const PRIMARY_HELPER = 'PHPCompiler\\ext\\intl\\LocaleParserJitHelper::primaryLanguageArgv';

    private const REGION_HELPER = 'PHPCompiler\\ext\\intl\\LocaleParserJitHelper::regionArgv';

    private const SCRIPT_HELPER = 'PHPCompiler\\ext\\intl\\LocaleParserJitHelper::scriptArgv';

    private const CANONICALIZE_HELPER = 'PHPCompiler\\ext\\intl\\LocaleParserJitHelper::canonicalizeArgv';

    private const DEFAULT_HELPER = 'PHPCompiler\\ext\\intl\\LocaleDefaultJitHelper::getDefaultArgv';

    private const HELPER_PATH_DEFAULT = '/ext/intl/LocaleDefaultJitHelper.php';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PRIMARY_HELPER,
        self::REGION_HELPER,
        self::SCRIPT_HELPER,
        self::CANONICALIZE_HELPER,
    ];

    /** @var list<string> */
    private const COMPILED_DEFAULT_HELPERS = [
        self::DEFAULT_HELPER,
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

    public static function invokeCanonicalize(Context $context, Value $locale): Value
    {
        self::ensureCanonicalizeLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_CANONICALIZE),
            $locale
        );
    }

    public static function invokeDefault(Context $context): Value
    {
        self::ensureDefaultLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_DEFAULT)
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

    public static function ensureCanonicalizeLinked(Context $context): void
    {
        self::ensureBridge(
            $context,
            self::ABI_CANONICALIZE,
            'locale_canonicalize_bridge_entry',
            self::CANONICALIZE_HELPER
        );
    }

    public static function ensureDefaultLinked(Context $context): void
    {
        self::ensureBridge(
            $context,
            self::ABI_DEFAULT,
            'locale_get_default_bridge_entry',
            self::DEFAULT_HELPER,
            [],
            self::HELPER_PATH_DEFAULT,
            self::COMPILED_DEFAULT_HELPERS
        );
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensurePrimaryLanguageLinked($context);
        self::ensureRegionLinked($context);
        self::ensureScriptLinked($context);
        self::ensureCanonicalizeLinked($context);
        self::ensureDefaultLinked($context);
    }

    private static function ensureBridge(
        Context $context,
        string $abi,
        string $entry,
        string $helper,
        ?array $paramTypes = null,
        ?string $helperPath = null,
        ?array $compiledHelpers = null
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
        $params = $paramTypes ?? [$strPtr];
        JitVmHelperLink::ensureBridge(
            $context,
            $abi,
            $entry,
            $params,
            $strPtr,
            $helper,
            $helperPath ?? self::HELPER_PATH,
            $compiledHelpers ?? self::COMPILED_HELPERS,
            '#20101'
        );
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
