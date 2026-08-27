<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_detect_order() — MbDetectOrderJitHelper (#35280).
 *
 * Canonical CSV order is kept in module global {@see G_ORDER_CSV} (NestedJIT statics are not
 * reliable across call sites — peer {@see MbInternalEncodingRuntime}).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_detect_order)
 */
final class MbDetectOrderRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbDetectOrderJitHelper.php';

    private const PARSE_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbDetectOrderJitHelper::parseOrderArgv';

    public const G_ORDER_CSV = '__phpc_mb_detect_order_csv';

    /** Default {@see MbstringState::detectOrder()} canonical CSV. */
    public const DEFAULT_ORDER_CSV = 'ASCII,UTF-8';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PARSE_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
        self::ensureOrderGlobal($context);
    }

    public static function parseHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::PARSE_LOGICAL, '#35280');
    }

    public static function orderCsvGlobal(Context $context): Value
    {
        self::ensureOrderGlobal($context);
        $g = $context->module->getNamedGlobal(self::G_ORDER_CSV);
        if (null === $g) {
            throw new \LogicException(self::G_ORDER_CSV.' missing (#35280)');
        }

        return $g;
    }

    private static function ensureOrderGlobal(Context $context): void
    {
        if (null !== $context->module->getNamedGlobal(self::G_ORDER_CSV)) {
            return;
        }
        $strPtr = $context->getTypeFromString('__string__*');
        $g = $context->module->addGlobal($strPtr, self::G_ORDER_CSV);
        // null → default ASCII,UTF-8 resolved in JitMbDetectOrder::lowerGet.
        $g->setInitializer($strPtr->constNull());
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            'mb_detect_order'
        );
    }
}
