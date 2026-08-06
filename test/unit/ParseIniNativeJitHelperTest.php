<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ext\standard\ParseIniNativeJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * Guard #26909 — NestedJIT helper parses NORMAL flat ini into native HT ops.
 *
 * Host-side smoke (no LLVM): ensures the helper source stays loadable and the
 * unsupported-mode gate returns 0. Full AOT execute is covered by the issue repro.
 */
final class ParseIniNativeJitHelperTest extends TestCase
{
    public function testUnsupportedModesReturnZeroWithoutNativeHt(): void
    {
        self::assertSame(
            0,
            ParseIniNativeJitHelper::parseIntoNative(1, "a=1\n", 1, 0),
            'process_sections must stay compile-time on NestedJIT path'
        );
        self::assertSame(
            0,
            ParseIniNativeJitHelper::parseIntoNative(1, "a=1\n", 0, 1),
            'non-NORMAL scanner must stay compile-time on NestedJIT path'
        );
        self::assertSame(
            0,
            ParseIniNativeJitHelper::parseIntoNative(0, "a=1\n", 0, 0),
            'invalid dest pointer'
        );
    }

    public function testHelperSourceAvoidsNestedJitHazards(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ParseIniNativeJitHelper.php');
        self::assertStringNotContainsString('explode(', $source);
        self::assertStringNotContainsString('preg_', $source);
        self::assertStringNotContainsString('ParseIniEngine', $source);
        self::assertStringContainsString('phpc_native_ht_set_string_key', $source);
        self::assertStringContainsString('#26909', $source);
    }

    public function testJitParseIniWiresHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitParseIni.php');
        self::assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        self::assertStringContainsString('ParseIniNativeJitHelper::parseIntoNative', $source);
        self::assertStringContainsString('#26909', $source);
    }
}
