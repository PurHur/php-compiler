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

    /**
     * #31989 rewrite assigned ensureStrtolDecl()'s void return to $parsed/$raw and
     * dropped the strtol() Value — HashTableWriteLlvm.php even failed to parse,
     * so every AOT compile aborted (#31966).
     */
    public function testEnsureStrtolDeclDoesNotStealCallResult(): void
    {
        foreach ([
            'lib/JIT/HashTableWriteLlvm.php',
            'lib/JIT/HashTableMergeLlvm.php',
            'lib/JIT/JitLongArg.php',
            'lib/VM/VmUnaryPlus.php',
            'lib/VM/VmValueCompare.php',
            'ext/standard/JitSessionStorageKernel.php',
            'ext/standard/JitSleep.php',
            'ext/standard/JitChr.php',
            'ext/standard/JitIntdiv.php',
            'ext/standard/JitImageTypeArg.php',
            'ext/filter/JitFilter.php',
        ] as $rel) {
            $path = __DIR__.'/../../'.$rel;
            $source = (string) file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression(
                '/\$\w+\s*=\s*\/\/ strtol\(3\) via LibcExtern::ensureStrtolDecl/',
                $source,
                "{$rel} must not assign ensureStrtolDecl() void to the strtol result"
            );
            try {
                token_get_all($source);
            } catch (\ParseError $e) {
                $this->fail("{$rel} must parse after #31989 strtol rewrite: ".$e->getMessage());
            }
        }
    }
}
