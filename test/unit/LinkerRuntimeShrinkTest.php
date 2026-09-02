<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\AOT\Linker;
use PHPUnit\Framework\TestCase;

/** lib/AOT/Linker.php must not call host shell_exec — use phpc_run_command (#8750, re-#2779). */
final class LinkerRuntimeShrinkTest extends TestCase
{
    public function testLinkerDoesNotCallHostShellExec(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        $this->assertDoesNotMatchRegularExpression('/\\\\shell_exec\\(/', $source);
    }

    public function testLinkerDoesNotEmbedBundledLiblzf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('ensureBundledLiblzf', $source);
        $this->assertStringNotContainsString('liblzf.a', $source);
        $this->assertStringContainsString('runCaptured', $source);
        $this->assertStringContainsString('phpc_run_command', $source);
    }

    public function testRunCapturedDelegatesToPhpcRunCommand(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        $this->assertStringContainsString('\\phpc_run_command($command)', $source);
    }

    public function testLinkLibsForUndefinedSymbolsEmptyWhenNoExternalDeps(): void
    {
        $libs = Linker::linkLibsForUndefinedSymbols(['malloc', 'free', 'printf', '__hashtable__alloc']);
        $this->assertSame('', $libs);
    }

    public function testLinkLibsForUndefinedSymbolsSelectsPcre2AndZ(): void
    {
        $libs = Linker::linkLibsForUndefinedSymbols(['pcre2_compile', 'inflate', 'deflate']);
        $this->assertStringContainsString('-lpcre2-8', $libs);
        $this->assertStringContainsString('-l:libz.so.1', $libs);
        $this->assertStringNotContainsString('-lcrypt', $libs);
        $this->assertStringNotContainsString('-l:libbz2.so.1.0', $libs);
    }

    public function testLinkLibsForUndefinedSymbolsSelectsOpensslAndSodium(): void
    {
        $libs = Linker::linkLibsForUndefinedSymbols(['EVP_DigestInit_ex', 'sodium_init']);
        $this->assertStringContainsString('-l:libcrypto.so.3', $libs);
        $this->assertStringContainsString('-l:libsodium.so.23', $libs);
    }
}
