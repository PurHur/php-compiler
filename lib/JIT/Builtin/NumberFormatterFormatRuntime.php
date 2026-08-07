<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value;

/**
 * JIT/AOT link for NumberFormatter::format() / numfmt_format() via NumberFormatterFormatJitHelper (#28648).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge}.
 * SSOT sniff: {@see \PHPCompiler\ext\intl\NumberFormatterFormatJitHelper::formatDecimalArgv}
 * php-src: ext/intl/formatter/formatter_main.c — PHP_FUNCTION(numfmt_format)
 */
final class NumberFormatterFormatRuntime
{
    private const ABI = 'phpc_numberformatter_format_decimal';

    private const HELPER_PATH = '/ext/intl/NumberFormatterFormatJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\intl\\NumberFormatterFormatJitHelper::formatDecimalArgv';

    private const BRIDGE_ENTRY = 'numberformatter_format_decimal_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function invoke(Context $context, Value $num): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI),
            $num
        );
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $double = $context->getTypeFromString('double');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$double],
            $strPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#28648'
        );
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
