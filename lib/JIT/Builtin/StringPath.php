<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for dirname()/basename() via PathJitHelper PHP (#15286).
 *
 * Replaces ~470 LOC inline LLVM in ext/standard/JitPath.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmString}.
 * php-src: ext/standard/dir.c, ext/standard/basename.c
 */
final class StringPath
{
    private const ABI_DIRNAME = 'phpc_dirname';

    private const ABI_DIRNAME_LEVELS = 'phpc_dirname_levels';

    private const ABI_BASENAME = 'phpc_basename';

    private const ABI_BASENAME_SUFFIX = 'phpc_basename_suffix';

    private const HELPER_PATH = '/ext/standard/PathJitHelper.php';

    private const DIRNAME_HELPER = 'PHPCompiler\\ext\\standard\\PathJitHelper::dirnameArgv';

    private const DIRNAME_LEVELS_HELPER = 'PHPCompiler\\ext\\standard\\PathJitHelper::dirnameWithLevelsArgv';

    private const BASENAME_HELPER = 'PHPCompiler\\ext\\standard\\PathJitHelper::basenameArgv';

    private const BASENAME_SUFFIX_HELPER = 'PHPCompiler\\ext\\standard\\PathJitHelper::basenameWithSuffixArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::DIRNAME_HELPER,
        self::DIRNAME_LEVELS_HELPER,
        self::BASENAME_HELPER,
        self::BASENAME_SUFFIX_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implementDirname($context);
        self::implementDirnameWithLevels($context);
        self::implementBasename($context);
        self::implementBasenameWithSuffix($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invokeDirname(Context $context, Value $path): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI_DIRNAME), $path);
    }

    public static function invokeDirnameWithLevels(Context $context, Value $path, Value $levels): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_DIRNAME_LEVELS),
            $path,
            $levels
        );
    }

    public static function invokeBasename(Context $context, Value $path): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI_BASENAME), $path);
    }

    public static function invokeBasenameWithSuffix(Context $context, Value $path, Value $suffix): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_BASENAME_SUFFIX),
            $path,
            $suffix
        );
    }

    private static function implementDirname(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_DIRNAME,
            'dirname_bridge_entry',
            [$strPtr],
            $strPtr,
            self::DIRNAME_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15286'
        );
    }

    private static function implementDirnameWithLevels(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_DIRNAME_LEVELS,
            'dirname_levels_bridge_entry',
            [$strPtr, $i64],
            $strPtr,
            self::DIRNAME_LEVELS_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15286'
        );
    }

    private static function implementBasename(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_BASENAME,
            'basename_bridge_entry',
            [$strPtr],
            $strPtr,
            self::BASENAME_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15286'
        );
    }

    private static function implementBasenameWithSuffix(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_BASENAME_SUFFIX,
            'basename_suffix_bridge_entry',
            [$strPtr, $strPtr],
            $strPtr,
            self::BASENAME_SUFFIX_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#15286'
        );
    }
}
