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
 * Packed order i64 in module global {@see G_ORDER_PACKED} (NestedJIT statics are not
 * reliable — peer {@see MbInternalEncodingRuntime}).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_detect_order)
 */
final class MbDetectOrderRuntime
{
    private const HELPER_PATH = '/ext/mbstring/MbDetectOrderJitHelper.php';

    private const PACK_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbDetectOrderJitHelper::packOrderArgv';

    private const JOINED_LOGICAL = 'PHPCompiler\\ext\\mbstring\\MbDetectOrderJitHelper::orderJoinedFromPackedArgv';

    public const G_ORDER_PACKED = '__phpc_mb_detect_order_packed';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PACK_LOGICAL,
        self::JOINED_LOGICAL,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
        self::ensureOrderGlobal($context);
    }

    public static function packHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::PACK_LOGICAL, '#35280');
    }

    public static function joinedFromPackedHelper(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::JOINED_LOGICAL, '#35280');
    }

    public static function orderPackedGlobal(Context $context): Value
    {
        self::ensureOrderGlobal($context);
        $g = $context->module->getNamedGlobal(self::G_ORDER_PACKED);
        if (null === $g) {
            throw new \LogicException(self::G_ORDER_PACKED.' missing (#35280)');
        }

        return $g;
    }

    private static function ensureOrderGlobal(Context $context): void
    {
        if (null !== $context->module->getNamedGlobal(self::G_ORDER_PACKED)) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $g = $context->module->addGlobal($i64, self::G_ORDER_PACKED);
        // 0 = unset → default ASCII,UTF-8 at getter time.
        $g->setInitializer($i64->constInt(0, false));
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
