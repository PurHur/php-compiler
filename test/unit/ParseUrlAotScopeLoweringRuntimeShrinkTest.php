<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Thin-AOT parse_url bridges must scope lowering to the ABI function (#33226 / #27211).
 *
 * Without BasicBlockHelper::scopeLoweringToFunction, BasicBlockHelper::append
 * attaches parse_url bridge blocks to user main and Module.php:180 rejects the IR.
 */
final class ParseUrlAotScopeLoweringRuntimeShrinkTest extends TestCase
{
    public function testParseUrlRuntimeScopesBridgeEmitToAbiFunction(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ParseUrlRuntime.php');
        $this->assertStringContainsString('#33226', $runtime);
        $this->assertStringContainsString('BasicBlockHelper::scopeLoweringToFunction', $runtime);
        $this->assertStringContainsString('implementIfMissing', $runtime);
        $this->assertStringContainsString('__phpc_parse_url_component', $runtime);
        $this->assertStringContainsString('__phpc_parse_url_assoc', $runtime);
        $this->assertStringContainsString('ParseUrlAssocLlvm::implement', $runtime);
    }

    public function testParseUrlAssocLlvmDocumentsScopeLoweringRequirement(): void
    {
        $assoc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ParseUrlAssocLlvm.php');
        $this->assertStringContainsString('#33226', $assoc);
        $this->assertStringContainsString('scopeLoweringToFunction', $assoc);
        $this->assertStringContainsString('#27211', $assoc);
    }

    public function testJitParseUrlEnsureLinksBeforeLookup(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../ext/standard/JitParseUrl.php');
        $this->assertStringContainsString('ParseUrlRuntime::ensureLinked', $jit);
        $this->assertStringContainsString('__phpc_parse_url_assoc', $jit);
        $this->assertStringContainsString('__phpc_parse_url_component', $jit);
    }

    public function testNoNewRuntimeCForParseUrl(): void
    {
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/parse_url.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/runtime/parse_url.c');
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/lib/AOT/runtime/phpc_parse_url.c');
    }
}
