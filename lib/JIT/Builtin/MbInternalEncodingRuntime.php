<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_internal_encoding() — MbInternalEncodingJitHelper (#35221).
 *
 * Encoding code is kept in module global {@see G_ENCODING_CODE} (NestedJIT statics are not
 * reliable across call sites — peer libxml AOT ring).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_internal_encoding)
 */
final class MbInternalEncodingRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbInternalEncodingJitHelper.php';

    private const CANON_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbInternalEncodingJitHelper::canonicalizeArgv';

    public const G_ENCODING_CODE = '__phpc_mb_internal_encoding_code';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CANON_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
        self::ensureEncodingGlobal($context);
    }

    public static function canonicalizeHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::CANON_LOGICAL, '#35221');
    }

    public static function encodingCodeGlobal(Context $context): Value
    {
        self::ensureEncodingGlobal($context);
        $g = $context->module->getNamedGlobal(self::G_ENCODING_CODE);
        if (null === $g) {
            throw new \LogicException(self::G_ENCODING_CODE.' missing (#35221)');
        }

        return $g;
    }

    private static function ensureEncodingGlobal(Context $context): void
    {
        if (null !== $context->module->getNamedGlobal(self::G_ENCODING_CODE)) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $g = $context->module->addGlobal($i64, self::G_ENCODING_CODE);
        // 0 = default UTF-8 at load time (resolved in JitMbInternalEncoding::lowerGet).
        $g->setInitializer($i64->constInt(0, false));
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_internal_encoding'
        );
    }
}
