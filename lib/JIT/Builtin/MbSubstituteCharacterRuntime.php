<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_substitute_character() — MbSubstituteCharacterJitHelper (#35263).
 *
 * Packed mode/codepoint is kept in module global {@see G_SUBST_CODE} (NestedJIT statics are not
 * reliable across call sites — peer {@see MbLanguageRuntime}).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_substitute_character)
 */
final class MbSubstituteCharacterRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbSubstituteCharacterJitHelper.php';

    private const CANON_STRING_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSubstituteCharacterJitHelper::canonicalizeStringArgv';

    private const CANON_LONG_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbSubstituteCharacterJitHelper::canonicalizeLongArgv';

    public const G_SUBST_CODE = '__phpc_mb_substitute_character_code';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CANON_STRING_LOGICAL,
        self::CANON_LONG_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
        self::ensureSubstGlobal($context);
    }

    public static function canonicalizeStringHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::CANON_STRING_LOGICAL, '#35263');
    }

    public static function canonicalizeLongHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::CANON_LONG_LOGICAL, '#35263');
    }

    public static function substCodeGlobal(Context $context): Value
    {
        self::ensureSubstGlobal($context);
        $g = $context->module->getNamedGlobal(self::G_SUBST_CODE);
        if (null === $g) {
            throw new \LogicException(self::G_SUBST_CODE.' missing (#35263)');
        }

        return $g;
    }

    private static function ensureSubstGlobal(Context $context): void
    {
        if (null !== $context->module->getNamedGlobal(self::G_SUBST_CODE)) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $g = $context->module->addGlobal($i64, self::G_SUBST_CODE);
        // Default MODE_CHAR codepoint 63 ('?') — php-src MBSTRG(default filter_illegal_substchar).
        $g->setInitializer($i64->constInt(63, false));
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_substitute_character'
        );
    }
}
