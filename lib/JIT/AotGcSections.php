<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM;

/**
 * Per-function ELF sections + link-time --gc-sections (#36198 part A).
 *
 * Helper-runtime TUs duplicate hundreds of runtime symbols across units; -z muldefs
 * keeps one definition but leaves every duplicate body in the linked binary until each
 * function lives in its own section and the linker can discard unreferenced ones.
 */
final class AotGcSections
{
    /** Opt-out: PHP_COMPILER_AOT_GC_SECTIONS=0 */
    public const ENV = 'PHP_COMPILER_AOT_GC_SECTIONS';

    /** Alias for strip suppression (issue #36198); {@see AotDebugSymbols::ENV} also disables strip. */
    public const STRIP_SUPPRESS_ENV = 'PHPC_DEBUG_SYMBOLS';

    public static function isEnabled(): bool
    {
        $env = getenv(self::ENV);
        if ('0' === $env || 'false' === strtolower((string) $env)) {
            return false;
        }

        return true;
    }

    public static function stripAtLink(): bool
    {
        if (AotDebugSymbols::isEnabled()) {
            return false;
        }
        $env = getenv(self::STRIP_SUPPRESS_ENV);

        return '1' !== $env && 'true' !== strtolower((string) $env);
    }

    /**
     * Tag every defined function with `.text.<symbol>` so ld --gc-sections can drop bodies
     * that are not reachable from main / llvm.global_ctors / .init_array.
     */
    public static function applyFunctionSections(PHPLLVM\LLVM $llvm, PHPLLVM\Module $module): void
    {
        if (!self::isEnabled()) {
            return;
        }
        $function = $module->getFirstFunction();
        while (null !== $function) {
            if ($function instanceof PHPLLVM\Value\Function_
                && $function->countBasicBlocks() > 0) {
                $name = self::llvmValueName($llvm, $function);
                if ('' !== $name) {
                    $llvm->lib->LLVMSetSection($function->value, '.text.'.$name);
                }
            }
            $function = $function->getNext();
        }
    }

    private static function llvmValueName(PHPLLVM\LLVM $llvm, PHPLLVM\Value $value): string
    {
        $raw = $llvm->lib->LLVMGetValueName($value->value);
        if (null === $raw) {
            return '';
        }

        return $raw->toString();
    }

    /** @param bool $asWlPrefix true for clang/gcc drivers */
    public static function linkGcSectionsFlag(bool $asWlPrefix): string
    {
        if (!self::isEnabled()) {
            return '';
        }

        return $asWlPrefix ? ' -Wl,--gc-sections ' : ' --gc-sections ';
    }

    /**
     * Whether to pass --gc-sections when helper-runtime unit objects participate in the link.
     *
     * Per-function helper sections are not reachable from {main} until common.o provides
     * the shared runtime root and duplicate bodies can be discarded (#36246).
     *
     * @param list<string> $helperObjectPaths paths from {@see HelperRuntimeCache::linkObjects()}
     */
    public static function linkGcSectionsFlagForHelperLink(bool $asWlPrefix, array $helperObjectPaths): string
    {
        if (!self::isEnabled()) {
            return '';
        }
        $hasGcSectionHelpers = false;
        foreach ($helperObjectPaths as $path) {
            if ($path === \PHPCompiler\AOT\HelperRuntimeCommon::commonObjectPath()) {
                continue;
            }
            if (\PHPCompiler\AOT\HelperRuntimeCache::unitObjectHasPerFunctionSections($path)) {
                $hasGcSectionHelpers = true;
                break;
            }
        }
        if ($hasGcSectionHelpers && !\PHPCompiler\AOT\HelperRuntimeCommon::isLinkEnabled()) {
            return '';
        }

        return self::linkGcSectionsFlag($asWlPrefix);
    }

    public static function linkStripFlag(): string
    {
        return self::stripAtLink() ? '-s ' : '';
    }
}
