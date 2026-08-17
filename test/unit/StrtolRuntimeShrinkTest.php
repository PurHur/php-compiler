<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * User-script strtol()/intval() stay PHP-in-PHP via ext/standard (#31988).
 * Libc strtol(3) is module-local after the LibcExtern always-on drop.
 */
final class StrtolRuntimeShrinkTest extends TestCase
{
    public function testUserScriptIntvalUsesPhpNotLibcOnly(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/intval.php');
        $this->assertStringContainsString('LibcExtern::ensureStrtolDecl', $builtin);
        $this->assertStringContainsString('#31988', $builtin);
    }

    public function testLibcExternDropsAlwaysOnStrtol(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'strtol' =>", $source);
        $this->assertStringContainsString('ensureStrtolDecl', $source);
        $this->assertStringContainsString('#31988', $source);
    }

    public function testHashTableRoutesStrtolThroughEnsureDecl(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('ensureLibcStrtol', $source);
        $this->assertStringContainsString('LibcExtern::ensureStrtolDecl', $source);
        $this->assertStringContainsString('#31988', $source);
    }
}
