<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitNextafterKernel;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for nextafter() via NextafterJitHelper PHP (#15062, #19259, #20034).
 *
 * Embed / non-thin: {@see NextafterJitHelper} via {@see JitVmHelperLink}.
 * Thin standalone AOT (`isThinStandaloneAotMain`, #20011): {@see JitNextafterKernel} libc.
 * Nested helper compile: libc leaf without re-entering NextafterJitHelper.
 * php-src: ext/standard/math.c — PHP_FUNCTION(nextafter)
 */
final class MathNextafter
{
    private const ABI_NEXTAFTER = 'phpc_nextafter';

    private const HELPER_PATH = '/ext/standard/NextafterJitHelper.php';

    private const NEXTAFTER_HELPER = 'PHPCompiler\\ext\\standard\\NextafterJitHelper::nextafterArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::NEXTAFTER_HELPER,
    ];

    private const BRIDGE_ENTRY = 'nextafter_bridge_entry';

    private const KERNEL_ENTRY = 'nextafter_kernel_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $num, Value $next): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return JitNextafterKernel::invoke($context, $num, $next);
        }

        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_NEXTAFTER),
            $num,
            $next
        );
    }

    private static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_NEXTAFTER);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)
            || JitVmHelperLink::hasNamedBridgeEntry($probe, self::KERNEL_ENTRY)) {
            $context->registerFunction(self::ABI_NEXTAFTER, $probe);

            return;
        }

        if ($context->isThinStandaloneAotMain()) {
            self::implementKernelBody($context, $probe);

            return;
        }

        $double = $context->getTypeFromString('double');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NEXTAFTER,
            self::BRIDGE_ENTRY,
            [$double, $double],
            $double,
            self::NEXTAFTER_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20034'
        );
    }

    private static function implementKernelBody(Context $context, ?LlvmFunction $probe): void
    {
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $double = $context->getTypeFromString('double');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI_NEXTAFTER,
                $context->context->functionType($double, false, $double, $double)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::KERNEL_ENTRY);
        $context->builder->positionAtEnd($entry);
        $result = JitNextafterKernel::invoke($context, $fn->getParam(0), $fn->getParam(1));
        $context->builder->returnValue($result);
        $context->registerFunction(self::ABI_NEXTAFTER, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
