<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;

/**
 * Cold-emit recording + edit-strip membership maps for AOT CompileCache (#36387).
 *
 * Extracted from {@see CompileCache} so beginRecording / record* / finishRecording stay
 * a separate TU (split-TU / size-budget ratchet) while the hub keeps thin public entry
 * points used by Context and NestedJIT. LLVM-symbol → project-member attribution lives
 * in {@see CompileCacheRecordingMemberPath}.
 *
 * Owns session start/teardown and export + user/helper symbol capture. Move-only —
 * no new C ABI.
 *
 * php-src analogy: Zend opcache records which scripts/functions belong to a cached image
 * so a later invalidation can strip only the dirty units (Zend/zend_file_cache.c /
 * Zend/zend_accelerator_module.c shape) — here the “units” are LLVM symbols keyed by
 * member path / scoped function name.
 */
trait CompileCacheRecording
{
    public static function beginRecording(string $key): void
    {
        self::$recordingKey = $key;
        self::$recordingExports = [];
        self::$recordingUserSymbols = [];
        self::$recordingHelperSymbols = [];
        self::$recordingUserSymbolsByMember = [];
        self::$recordingUserSymbolsByFunction = [];
    }

    public static function setBundledSource(string $source): void
    {
        self::$bundledSource = $source;
    }

    /**
     * @param list<string> $paths
     */
    public static function setEditChangedMembers(array $paths): void
    {
        $clean = [];
        foreach ($paths as $path) {
            if (!is_string($path) || '' === $path) {
                continue;
            }
            $resolved = realpath($path);
            $clean[] = false !== $resolved ? $resolved : $path;
        }
        self::$editChangedMembers = array_values(array_unique($clean));
    }

    /** @return list<string> */
    public static function editChangedMembers(): array
    {
        return self::$editChangedMembers;
    }

    /**
     * @param array<string, array<string, true|int|string>> $pathToScoped
     */
    public static function setEditChangedFunctions(array $pathToScoped): void
    {
        $clean = [];
        foreach ($pathToScoped as $path => $scopedMap) {
            if (!is_string($path) || '' === $path || !is_array($scopedMap)) {
                continue;
            }
            $resolved = realpath($path);
            $key = false !== $resolved ? $resolved : $path;
            $funcs = [];
            foreach ($scopedMap as $scoped => $flag) {
                if (!is_string($scoped) || '' === $scoped) {
                    continue;
                }
                if (false === $flag || null === $flag) {
                    continue;
                }
                $funcs[strtolower($scoped)] = true;
            }
            if ([] !== $funcs) {
                $clean[$key] = $funcs;
            }
        }
        self::$editChangedFunctions = $clean;
    }

    /** @return array<string, array<string, true>> */
    public static function editChangedFunctions(): array
    {
        return self::$editChangedFunctions;
    }

    public static function isRecording(): bool
    {
        return null !== self::$recordingExports;
    }

    public static function recordExport(string $llvmName, string $signature, Block $block): void
    {
        if (null === self::$recordingExports) {
            return;
        }
        self::$recordingExports[] = [
            'llvm' => $llvmName,
            'signature' => $signature,
            'scoped' => self::blockScopedName($block),
        ];
    }

    /** Record a user-TU LLVM symbol (not NestedJIT) for edit-scaffold stripping (#36387). */
    public static function recordUserLlvmSymbol(string $llvmName, ?\PHPCompiler\Block $block = null): void
    {
        if (null === self::$recordingUserSymbols || '' === $llvmName) {
            return;
        }
        self::$recordingUserSymbols[] = $llvmName;
        if (null === self::$recordingUserSymbolsByMember) {
            return;
        }
        $member = self::memberPathForBlock($block);
        if ('' === $member) {
            return;
        }
        if (!isset(self::$recordingUserSymbolsByMember[$member])) {
            self::$recordingUserSymbolsByMember[$member] = [];
        }
        self::$recordingUserSymbolsByMember[$member][] = $llvmName;

        if (null === self::$recordingUserSymbolsByFunction || null === $block || null === $block->func) {
            return;
        }
        $fname = $block->func->name;
        if (!is_string($fname) || '' === $fname || '{main}' === $fname || str_starts_with($fname, '{')) {
            return;
        }
        $scoped = $block->func->getScopedName();
        if (!is_string($scoped) || '' === $scoped) {
            return;
        }
        if (!isset(self::$recordingUserSymbolsByFunction[$member])) {
            self::$recordingUserSymbolsByFunction[$member] = [];
        }
        if (!isset(self::$recordingUserSymbolsByFunction[$member][$scoped])) {
            self::$recordingUserSymbolsByFunction[$member][$scoped] = [];
        }
        self::$recordingUserSymbolsByFunction[$member][$scoped][] = $llvmName;
    }

    /** Record NestedJIT helper logical→LLVM so edit scaffold can rebind without re-NestedJIT (#36387). */
    public static function recordHelperLogical(string $logicalLc, string $llvmName): void
    {
        if (null === self::$recordingHelperSymbols || '' === $logicalLc || '' === $llvmName) {
            return;
        }
        self::$recordingHelperSymbols[strtolower($logicalLc)] = $llvmName;
    }

    public static function finishRecording(): void
    {
        self::$recordingKey = null;
        self::$recordingExports = null;
        self::$recordingUserSymbols = null;
        self::$recordingHelperSymbols = null;
        self::$recordingUserSymbolsByMember = null;
        self::$recordingUserSymbolsByFunction = null;
        self::$editScaffoldByFunction = [];
        self::$bundledSource = null;
        self::$editChangedMembers = [];
        self::$editChangedFunctions = [];
        self::$keptUserSymbols = [];
        self::$strippedUserSymbols = [];
        self::$skipModuleFuncCompile = false;
        self::$editScaffoldActive = false;
        self::$editScaffoldPartial = false;
        self::$partialEmitBaseObject = null;
        self::$editScaffoldBitcodeBound = false;
        self::$pendingEditScaffoldKey = null;
        self::$projectMembers = null;
        self::$projectEntry = null;
    }

    private static function blockScopedName(Block $block): string
    {
        if (null !== $block->func) {
            return $block->func->getScopedName();
        }

        return '{main}';
    }
}
