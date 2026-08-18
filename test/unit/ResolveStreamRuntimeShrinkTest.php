<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * User-script fopen()/fwrite() stay StreamIo PHP helpers (#32287).
 * __phpc_resolve_stream is module-local after the LibcExtern always-on drop.
 */
final class ResolveStreamRuntimeShrinkTest extends TestCase
{
    public function testLibcExternDropsAlwaysOnResolveStream(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'__phpc_resolve_stream' =>", $source);
        $this->assertStringContainsString('ensureResolveStreamDecl', $source);
        $this->assertStringContainsString('#32287', $source);
        $this->assertStringContainsString("'syscall' =>", $source);
        $this->assertStringContainsString("'__phpc_host_php_write' =>", $source);
        $this->assertStringContainsString("'__phpc_host_snprintf' =>", $source);
    }

    public function testNestedJitStreamKernelsDeclareResolveStreamModuleLocally(): void
    {
        $io = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
        $this->assertStringContainsString('LibcExtern::ensureResolveStreamDecl', $io);
        $this->assertStringContainsString('#32287', $io);
        $this->assertStringContainsString("lookupFunction('__phpc_resolve_stream')", $io);
        $this->assertStringNotContainsString("['__phpc_resolve_stream'", $io);

        $sync = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamSyncKernel.php');
        $this->assertStringContainsString('LibcExtern::ensureResolveStreamDecl', $sync);
        $this->assertStringContainsString('#32287', $sync);
        $this->assertStringContainsString("lookupFunction('__phpc_resolve_stream')", $sync);
        $this->assertStringNotContainsString("['__phpc_resolve_stream'", $sync);
    }

    public function testStandaloneImplementerReusesEnsureDecl(): void
    {
        $standalone = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamGlobalsJit.php');
        $this->assertStringContainsString('LibcExtern::ensureResolveStreamDecl', $standalone);
        $this->assertStringContainsString('#32287', $standalone);
        $embed = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamLibcHandleKernel.php');
        $this->assertStringContainsString("getNamedFunction(\$abiName)", $embed);
        $this->assertStringContainsString("'__phpc_resolve_stream'", $embed);
    }

    public function testPhpcStreamCStaysDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/phpc_stream.c');
    }
}
