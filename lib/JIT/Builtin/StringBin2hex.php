<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\standard\JitBin2hexKernel;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for bin2hex() via Bin2hexJitHelper PHP (#14603, #18884, #20011).
 *
 * When JIT modules are registered: {@see Bin2hexJitHelper} via {@see JitVmHelperLink}.
 * Thin standalone AOT (`isThinStandaloneAotMain`, #15417): {@see JitBin2hexKernel} hex loop
 * so nested helper TUs are not ExternalMethod-stubbed (#16075).
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/string.c — PHP_FUNCTION(bin2hex)
 */
final class StringBin2hex
{
    private const ABI = '__compiler_bin2hex';

    private const HELPER_PATH = '/ext/standard/Bin2hexJitHelper.php';

    private const BIN2HEX_HELPER = 'PHPCompiler\\ext\\standard\\Bin2hexJitHelper::bin2hexArgv';

    private const BRIDGE_ENTRY = 'bin2hex_bridge_entry';

    private const KERNEL_ENTRY = 'bin2hex_kernel_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::BIN2HEX_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)
            || JitVmHelperLink::hasNamedBridgeEntry($probe, self::KERNEL_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        // User-script / bootstrap thin standalone: emit hex kernel (former defer path).
        if ($context->isThinStandaloneAotMain()) {
            self::implementKernelBody($context, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementBridge($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI,
            self::BRIDGE_ENTRY,
            [$strPtr],
            $strPtr,
            self::BIN2HEX_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20011'
        );
    }

    private static function implementKernelBody(Context $context, ?LlvmFunction $probe): void
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($strPtr, false, $strPtr)
            );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::KERNEL_ENTRY);
        $context->builder->positionAtEnd($entry);
        JitBin2hexKernel::emitBody($context, $fn);
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
