<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ExplodeJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\HashTable;
use PHPUnit\Framework\TestCase;

/**
 * explode() JIT/AOT: LLVM runtime emit + host ExplodeJitHelper SSOT (#14750 / #27660).
 */
final class ExplodeRuntimeShrinkTest extends TestCase
{
    public function testStringExplodeUsesJitExplodeRuntimeEmit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringExplode.php');
        $this->assertStringContainsString('JitExplode::explode', $source);
        $this->assertStringNotContainsString('JitVmHelperLink', $source);

        $jitExplode = (string) file_get_contents(__DIR__.'/../../ext/standard/JitExplode.php');
        $this->assertStringContainsString('function explode(', $jitExplode);
        $this->assertStringContainsString('buildPackedStrings', $jitExplode);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/explode.php');
        $this->assertStringContainsString('StringExplode::ensureLinked', $builtin);
        $this->assertStringContainsString('StringExplode::invoke', $builtin);
        $this->assertStringContainsString('buildPackedStrings($context, $delimLit, $hayLit, \\PHP_INT_MAX)', $builtin);
    }

    public function testExplodeJitHelperHostParityWithVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ExplodeJitHelper.php');
        // Host SSOT must not call VmString (NestedJIT stub hazard for #27660).
        $this->assertStringNotContainsString('VmString::', $source);

        $expected = VmString::explode(',', 'a,b,c', 2);
        $ht = ExplodeJitHelper::explodeArgv(',', 'a,b,c', 2);
        $this->assertInstanceOf(HashTable::class, $ht);
        $actual = [];
        foreach ($ht->iterate(true) as $value) {
            $actual[] = $value->toString();
        }
        $this->assertSame($expected, $actual);
    }

    public function testExplodeJitHelperMatchesVmStringNegativeLimit(): void
    {
        $expected = VmString::explode(',', 'a,b,c', -1);
        $ht = ExplodeJitHelper::explodeArgv(',', 'a,b,c', -1);
        $actual = [];
        foreach ($ht->iterate(true) as $value) {
            $actual[] = $value->toString();
        }
        $this->assertSame($expected, $actual);
    }

    public function testSpineBundleIncludesExplodePaths(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ExplodeJitHelper.php', $spine);
        $this->assertStringContainsString('JitExplode.php', $spine);
        $this->assertStringContainsString('StringExplode.php', $spine);
    }
}
