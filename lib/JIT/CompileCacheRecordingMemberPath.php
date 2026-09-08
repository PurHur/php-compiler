<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * LLVM-symbol → project-member path attribution for AOT CompileCache recording (#36387).
 *
 * Extracted from {@see CompileCacheRecording} so session start/teardown + record*
 * stay separate from SourceBundler / declaring-function member resolution (split-TU /
 * compile-cache iterability). Used by {@see CompileCacheRecording::recordUserLlvmSymbol()}
 * via the CompileCache hub composition.
 *
 * Move-only — no new C ABI. php-src analogy: Zend opcache maps cached functions back
 * to the script that owns them for selective invalidation (Zend/zend_file_cache.c /
 * Zend/zend_accelerator_module.c shape).
 */
trait CompileCacheRecordingMemberPath
{
    private static function memberPathForBlock(?\PHPCompiler\Block $block): string
    {
        if (null === $block) {
            return '';
        }
        // Named user functions: prefer the project member that declares them. Bundled
        // opcode startLine often lands on the call site in the entry file after
        // SourceBundler concat, which swapped greeting→main.php (#36387).
        if (null !== $block->func) {
            $fname = $block->func->name;
            if (
                is_string($fname)
                && '' !== $fname
                && '{main}' !== $fname
                && !str_starts_with($fname, '{')
            ) {
                $declared = self::memberPathDeclaringFunction($fname);
                if ('' !== $declared) {
                    return $declared;
                }
            }
            if ('{main}' === $fname) {
                if (is_string(self::$projectEntry) && '' !== self::$projectEntry) {
                    return self::$projectEntry;
                }
            }
        }
        $line = 0;
        foreach ($block->opCodes as $op) {
            if (null !== $op->sourceLocation && $op->sourceLocation->startLine > 0) {
                $line = $op->sourceLocation->startLine;
                break;
            }
        }
        if (is_string(self::$bundledSource) && '' !== self::$bundledSource && $line > 0) {
            $mapped = \PHPCompiler\Web\SourceBundler::mapBundledLine(self::$bundledSource, $line);
            if (is_array($mapped) && isset($mapped[0]) && is_string($mapped[0]) && '' !== $mapped[0]) {
                $resolved = realpath($mapped[0]);

                return false !== $resolved ? $resolved : $mapped[0];
            }
        }
        $script = $block->scriptPath();
        if ('' === $script) {
            return '';
        }
        $resolved = realpath($script);

        return false !== $resolved ? $resolved : $script;
    }

    /**
     * Absolute path of the project member that declares `function $name` (#36387).
     */
    private static function memberPathDeclaringFunction(string $name): string
    {
        $members = self::$projectMembers ?? [];
        if ([] === $members || '' === $name) {
            return '';
        }
        $re = '/function\s+'.preg_quote($name, '/').'\s*\(/i';
        $hits = [];
        foreach ($members as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }
            $src = @file_get_contents($path);
            if (!is_string($src) || !preg_match($re, $src)) {
                continue;
            }
            $resolved = realpath($path);
            $hits[] = false !== $resolved ? $resolved : $path;
        }
        if (1 === count($hits)) {
            return $hits[0];
        }

        return '';
    }
}
