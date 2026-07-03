<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for pathinfo() extension/filename via PathinfoJitHelper PHP (#15322).
 *
 * Replaces ~185 LOC inline LLVM in ext/standard/JitPathinfo.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/basic_functions.c — php_pathinfo
 */
final class StringPathinfo
{
    private const ABI_EXTENSION = 'phpc_pathinfo_extension';

    private const ABI_FILENAME = 'phpc_pathinfo_filename';

    private const ABI_COMPONENT = 'phpc_pathinfo_component';

    private const HELPER_PATH = '/ext/standard/PathinfoJitHelper.php';

    private const EXTENSION_HELPER = 'PHPCompiler\\ext\\standard\\PathinfoJitHelper::extensionArgv';

    private const FILENAME_HELPER = 'PHPCompiler\\ext\\standard\\PathinfoJitHelper::filenameArgv';

    private const COMPONENT_HELPER = 'PHPCompiler\\ext\\standard\\PathinfoJitHelper::componentArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EXTENSION_HELPER,
        self::FILENAME_HELPER,
        self::COMPONENT_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementExtension($context);
        self::implementFilename($context);
        self::implementComponent($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invokeExtension(Context $context, Value $path): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI_EXTENSION), $path);
    }

    public static function invokeFilename(Context $context, Value $path): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI_FILENAME), $path);
    }

    public static function invokeComponent(Context $context, Value $path, int $mask): Value
    {
        self::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction(self::ABI_COMPONENT),
            $path,
            $i64->constInt($mask, false)
        );
    }

    private static function implementExtension(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_EXTENSION,
            'pathinfo_extension_bridge_entry',
            [$strPtr],
            $strPtr,
            self::EXTENSION_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15322'
        );
    }

    private static function implementFilename(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_FILENAME,
            'pathinfo_filename_bridge_entry',
            [$strPtr],
            $strPtr,
            self::FILENAME_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15322'
        );
    }

    private static function implementComponent(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_COMPONENT,
            'pathinfo_component_bridge_entry',
            [$strPtr, $i64],
            $strPtr,
            self::COMPONENT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15322'
        );
    }
}
