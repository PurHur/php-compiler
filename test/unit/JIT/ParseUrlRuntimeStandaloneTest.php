<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #5257 / #5913: parse_url() LLVM helpers replace phpc_parse_url.c.
 *
 * @group aot-lint
 */
final class ParseUrlRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesParseUrlC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_parse_url.c');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_parse_url.c', $linker);
        $jit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/ParseUrlJit.php');
        $this->assertStringContainsString('__phpc_parse_url_component', $jit);
        $this->assertStringContainsString('__phpc_parse_url_assoc', $jit);
        $this->assertStringContainsString('__string__strlen', $jit);
        $this->assertStringNotContainsString("lookupFunction('strlen'), \$url)", $jit);
        $this->assertStringContainsString('mirrors phpc_parse_url.c', $jit);
        $bridge = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/ParseUrl.php');
        $this->assertStringContainsString('ParseUrlJit', $bridge);
    }
}
