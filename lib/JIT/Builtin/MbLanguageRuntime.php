<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_language() — MbLanguageJitHelper (#35259).
 *
 * Language code is kept in module global {@see G_LANGUAGE_CODE} (NestedJIT statics are not
 * reliable across call sites — peer {@see MbInternalEncodingRuntime}).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_language)
 */
final class MbLanguageRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbLanguageJitHelper.php';

    private const CANON_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbLanguageJitHelper::canonicalizeArgv';

    public const G_LANGUAGE_CODE = '__phpc_mb_language_code';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CANON_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
        self::ensureLanguageGlobal($context);
    }

    public static function canonicalizeHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::CANON_LOGICAL, '#35259');
    }

    public static function languageCodeGlobal(Context $context): Value
    {
        self::ensureLanguageGlobal($context);
        $g = $context->module->getNamedGlobal(self::G_LANGUAGE_CODE);
        if (null === $g) {
            throw new \LogicException(self::G_LANGUAGE_CODE.' missing (#35259)');
        }

        return $g;
    }

    private static function ensureLanguageGlobal(Context $context): void
    {
        if (null !== $context->module->getNamedGlobal(self::G_LANGUAGE_CODE)) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $g = $context->module->addGlobal($i64, self::G_LANGUAGE_CODE);
        // 0 = default neutral at load time (resolved in JitMbLanguage::lowerGet).
        $g->setInitializer($i64->constInt(0, false));
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_language'
        );
    }
}
