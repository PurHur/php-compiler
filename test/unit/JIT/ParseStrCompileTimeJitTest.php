<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #6308 phase 2: parse_str() JIT compile-time literals use ParseStrEngine, not __compiler_parse_str.
 *
 * @group aot-lint
 */
final class ParseStrCompileTimeJitTest extends TestCase
{
    public function testJitParseStrUsesParseStrEngineForCompileTimeLiterals(): void
    {
        $jitParseStr = (string) file_get_contents(__DIR__.'/../../../ext/standard/JitParseStr.php');
        $this->assertStringContainsString('JitParseStrMaterializer::materializeParsed', $jitParseStr);
        $this->assertStringContainsString('ParseStrEngine::parse', $jitParseStr);
        $this->assertStringContainsString('compileTimeLiteral', $jitParseStr);

        $parseStr = (string) file_get_contents(__DIR__.'/../../../ext/standard/parse_str.php');
        $this->assertStringContainsString('compileTimeLiteral', $parseStr);
        $this->assertStringContainsString('StringParseStr::ensureLinked', $parseStr);
    }

    public function testParseStrMaterializerLivesInExtStandard(): void
    {
        $this->assertFileExists(__DIR__.'/../../../ext/standard/JitParseStrMaterializer.php');
        $source = (string) file_get_contents(__DIR__.'/../../../ext/standard/JitParseStrMaterializer.php');
        $this->assertStringContainsString('ParseStrEngine', $source);
        $this->assertStringNotContainsString('StringParseStrJit', $source);
    }
}
