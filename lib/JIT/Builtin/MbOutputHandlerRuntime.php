<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_output_handler() — MbOutputHandlerJitHelper (#20014 runtime args).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_output_handler)
 */
final class MbOutputHandlerRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbOutputHandlerJitHelper.php';

    private const CONVERT_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbOutputHandlerJitHelper::convertArgv';

    public const G_OUTCONV_ENABLED = '__phpc_mb_output_handler_outconv_enabled';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CONVERT_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
        self::ensureOutconvGlobal($context);
    }

    public static function convertHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::CONVERT_LOGICAL, '#20014');
    }

    public static function outconvGlobal(Context $context): Value
    {
        self::ensureOutconvGlobal($context);
        $g = $context->module->getNamedGlobal(self::G_OUTCONV_ENABLED);
        if (null === $g) {
            throw new \LogicException(self::G_OUTCONV_ENABLED.' missing (#20014)');
        }

        return $g;
    }

    private static function ensureOutconvGlobal(Context $context): void
    {
        if (null !== $context->module->getNamedGlobal(self::G_OUTCONV_ENABLED)) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $g = $context->module->addGlobal($i64, self::G_OUTCONV_ENABLED);
        $g->setInitializer($i64->constInt(0, false));
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_output_handler',
            true
        );
    }
}
