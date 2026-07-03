<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for spl_autoload() / spl_autoload_extensions() via SplAutoloadDefaultJitHelper PHP.
 *
 * SSOT: {@see \PHPCompiler\ext\standard\VmSplAutoload}
 * php-src: ext/spl/php_spl.c — PHP_FUNCTION(spl_autoload), PHP_FUNCTION(spl_autoload_extensions)
 */
final class SplAutoloadDefaultRuntime
{
    private const ABI_DEFAULT = '__phpc_spl_autoload_default';

    private const ABI_EXTENSIONS = '__phpc_spl_autoload_extensions';

    private const HELPER_PATH = '/ext/standard/SplAutoloadDefaultJitHelper.php';

    private const DEFAULT_HELPER = 'PHPCompiler\\ext\\standard\\SplAutoloadDefaultJitHelper::defaultAutoloadArgv';

    private const EXTENSIONS_HELPER = 'PHPCompiler\\ext\\standard\\SplAutoloadDefaultJitHelper::extensionsArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::DEFAULT_HELPER,
        self::EXTENSIONS_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function invokeDefault(
        Context $context,
        Value $className,
        Value $hasFileExts,
        Value $fileExts
    ): void {
        self::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction(self::ABI_DEFAULT),
            $className,
            $hasFileExts,
            $fileExts
        );
    }

    public static function invokeExtensions(
        Context $context,
        Value $hasArg,
        Value $fileExts
    ): Value {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_EXTENSIONS),
            $hasArg,
            $fileExts
        );
    }

    private static function implement(Context $context): void
    {
        $defaultProbe = $context->module->getNamedFunction(self::ABI_DEFAULT);
        $extensionsProbe = $context->module->getNamedFunction(self::ABI_EXTENSIONS);
        if (
            null !== $defaultProbe && $defaultProbe->countBasicBlocks() > 0
            && null !== $extensionsProbe && $extensionsProbe->countBasicBlocks() > 0
        ) {
            $context->registerFunction(self::ABI_DEFAULT, $defaultProbe);
            $context->registerFunction(self::ABI_EXTENSIONS, $extensionsProbe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $voidTy = $context->getTypeFromString('void');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_DEFAULT,
            'spl_autoload_default_bridge_entry',
            [$strPtr, $i1, $strPtr],
            $voidTy,
            self::DEFAULT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#4256'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_EXTENSIONS,
            'spl_autoload_extensions_bridge_entry',
            [$i1, $strPtr],
            $strPtr,
            self::EXTENSIONS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#4256'
        );

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
