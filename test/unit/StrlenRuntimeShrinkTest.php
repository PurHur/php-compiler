<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * User-script strlen() stays PHP-in-PHP via ext/types (#32068).
 * Libc strlen(3) is module-local after the LibcExtern always-on drop.
 */
final class StrlenRuntimeShrinkTest extends TestCase
{
    public function testUserScriptStrlenUsesPhpNotLibcOnly(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/types/strlen.php');
        $this->assertStringContainsString('JitStrlen::lowerLength', $builtin);
        $this->assertStringContainsString('VmString::byteLength', $builtin);
        $this->assertStringNotContainsString("lookupFunction('strlen')", $builtin);
    }

    public function testLibcExternDropsAlwaysOnStrlen(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'strlen' =>", $source);
        $this->assertStringContainsString('ensureStrlenDecl', $source);
        $this->assertStringContainsString('#32068', $source);
    }

    public function testCliArgvRoutesStrlenThroughEnsureDecl(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CliArgvRuntime.php');
        $this->assertStringContainsString('LibcExtern::ensureStrlenDecl', $source);
        $this->assertStringContainsString('#32068', $source);
    }
}
