<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #5257 / #5913 / #9358: parse_url() LLVM helpers route through ParseUrlJitHelper PHP.
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
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/ParseUrlJit.php');
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/ParseUrlRuntime.php');
        $this->assertStringContainsString('__phpc_parse_url_component', $runtime);
        $this->assertStringContainsString('__phpc_parse_url_assoc', $runtime);
        $this->assertStringContainsString('ParseUrlJitHelper', $runtime);
        $bridge = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/ParseUrl.php');
        $this->assertStringContainsString('ParseUrlRuntime', $bridge);
    }
}
