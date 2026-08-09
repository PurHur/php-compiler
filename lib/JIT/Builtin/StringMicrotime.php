<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitMicrotimeKernel;
use PHPLLVM\Value;

/**
 * JIT/AOT link for microtime() via MicrotimeJitHelper PHP (#29405).
 *
 * Embed + thin standalone AOT: {@see MicrotimeJitHelper} via {@see JitVmHelperLink}
 * (gethostname #29364 / getenv #29313 shape).
 * Nested helper compile: `@microtime` → {@see JitMicrotimeKernel} thin gettimeofday leaf
 * without re-entering MicrotimeJitHelper (former always-on LLVM #26930).
 * SSOT for VM: {@see \PHPCompiler\ext\standard\VmDate::microtime()}.
 * php-src: ext/standard/microtime.c — PHP_FUNCTION(microtime)
 */
final class StringMicrotime
{
    private const HELPER_PATH = '/ext/standard/MicrotimeJitHelper.php';

    private const FLOAT_HELPER = 'PHPCompiler\\ext\\standard\\MicrotimeJitHelper::microtimeFloat';

    private const STRING_HELPER = 'PHPCompiler\\ext\\standard\\MicrotimeJitHelper::microtimeString';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FLOAT_HELPER,
        self::STRING_HELPER,
    ];

    private const ABI_FLOAT = '__compiler_microtime_float';

    private const ABI_STRING = '__compiler_microtime_string';

    private const BRIDGE_FLOAT_ENTRY = 'microtime_float_bridge_entry';

    private const BRIDGE_STRING_ENTRY = 'microtime_string_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    /** @return Value double — seconds since epoch with fractional usec */
    public static function invokeFloat(Context $context): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitMicrotimeKernel::invokeFloat($context);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI_FLOAT));
    }

    /** @return Value `__string__*` — "usec sec" string form */
    public static function invokeString(Context $context): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitMicrotimeKernel::invokeString($context);
        }

        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI_STRING));
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $strProbe = $context->module->getNamedFunction(self::ABI_STRING);
        if (JitVmHelperLink::hasNamedBridgeEntry($strProbe, self::BRIDGE_STRING_ENTRY)) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $doubleTy = $context->getTypeFromString('double');

        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FLOAT,
            self::BRIDGE_FLOAT_ENTRY,
            [],
            $doubleTy,
            self::FLOAT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#29405'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_STRING,
            self::BRIDGE_STRING_ENTRY,
            [],
            $strPtr,
            self::STRING_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#29405'
        );
        self::registerLinkedRuntime($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::ABI_FLOAT, self::ABI_STRING] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringMicrotime bridge (#29405)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
