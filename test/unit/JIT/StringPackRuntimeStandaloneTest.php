<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #5231: pack() LLVM helpers replace phpc_pack.c in AOT runtime.
 *
 * @group aot-lint
 */
final class StringPackRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesPhpcPackC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/phpc_pack.c');
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_pack.c', $linker);
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/PackJitRuntime.php');
        $this->assertStringContainsString('__compiler_pack', $runtime);
        $engine = (string) file_get_contents(__DIR__.'/../../../ext/standard/PackEngine.php');
        $this->assertStringContainsString('PackEngine', $engine);
    }
}
