<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link hook for mb_detect_order() — MbDetectOrderJitHelper (#35280 / #35856).
 *
 * Canonical CSV order is kept in module global {@see G_ORDER_CSV} (NestedJIT statics are not
 * reliable across call sites — peer {@see MbInternalEncodingRuntime}).
 *
 * NestedJIT lowers typed string params as boxed `__value__`; thin AOT must call via an
 * ABI bridge (`__string__*`) rather than bare `lookupCompiled` + `builder->call`
 * (peer {@see MbDetectEncodingRuntime::implementDetect}).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_detect_order)
 */
final class MbDetectOrderRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbDetectOrderJitHelper.php';

    private const PARSE_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbDetectOrderJitHelper::parseOrderArgv';

    private const ABI_PARSE = 'phpc_mb_detect_order_parse';

    private const BRIDGE_PARSE = 'mb_detect_order_parse_bridge_entry';

    public const G_ORDER_CSV = '__phpc_mb_detect_order_csv';

    /** Default {@see MbstringState::detectOrder()} canonical CSV. */
    public const DEFAULT_ORDER_CSV = 'ASCII,UTF-8';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PARSE_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementParse($context);
        self::ensureOrderGlobal($context);
    }

    public static function parseHelper(Context $context): LlvmFunction
    {
        self::ensureLinked($context);

        return $context->lookupFunction(self::ABI_PARSE);
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

    private static function implementParse(Context $context): void
    {
        if (NestedJitCompileScope::isActive() && !\PHPCompiler\AOT\HelperRuntimeCache::enabled()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_PARSE);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_PARSE)) {
            $context->registerFunction(self::ABI_PARSE, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_PARSE,
            self::BRIDGE_PARSE,
            [$strPtr],
            $strPtr,
            self::PARSE_LOGICAL,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#35856'
        );
    }
}
