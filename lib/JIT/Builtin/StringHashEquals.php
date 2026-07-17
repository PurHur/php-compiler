<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\hash\JitHashEqualsKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_hash_equals via HashEqualsJitHelper PHP (#9164, #20065).
 *
 * Embed / non-thin: {@see HashEqualsJitHelper} via {@see JitVmHelperLink}.
 * Thin standalone AOT: {@see JitHashEqualsKernel} timing-safe XOR (#20050 shape).
 * SSOT {@see \PHPCompiler\ext\standard\VmHash::equals}.
 * php-src: ext/hash/hash.c — hash_equals()
 */
final class StringHashEquals
{
    private const HELPER_PATH = '/ext/standard/HashEqualsJitHelper.php';

    private const EQUALS_HELPER = 'PHPCompiler\\ext\\standard\\HashEqualsJitHelper::equals';

    private const KERNEL_ENTRY = 'hash_equals_kernel_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EQUALS_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $abiName = '__compiler_hash_equals';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'hash_equals_bridge_entry')
            || JitVmHelperLink::hasNamedBridgeEntry($probe, self::KERNEL_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        if ($context->isThinStandaloneAotMain()) {
            JitHashEqualsKernel::implement($context);

            return;
        }

        self::ensureJitHelperCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $strPtr, $strPtr)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, 'hash_equals_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::EQUALS_HELPER),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($context->builder->zext($result, $i32));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#20065');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20065'
        );
    }
}
