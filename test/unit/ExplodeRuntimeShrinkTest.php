<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ExplodeJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\HashTable;
use PHPUnit\Framework\TestCase;

/** explode() JIT routes through ExplodeJitHelper PHP not inline LLVM (#14750). */
final class ExplodeRuntimeShrinkTest extends TestCase
{
    public function testStringExplodeUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringExplode.php');
        $this->assertStringContainsString('ExplodeJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);

        $jitExplode = (string) file_get_contents(__DIR__.'/../../ext/standard/JitExplode.php');
        $this->assertStringNotContainsString('explode_head_', $jitExplode);
        $this->assertStringNotContainsString('explodeNegativeLimit', $jitExplode);
        $this->assertStringContainsString('buildPackedStrings', $jitExplode);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/explode.php');
        $this->assertStringContainsString('StringExplode::ensureLinked', $builtin);
        $this->assertStringContainsString('StringExplode::invoke', $builtin);
        $this->assertStringNotContainsString('JitExplode::explode', $builtin);
    }

    public function testExplodeJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ExplodeJitHelper.php');
        $this->assertStringContainsString('VmString::explode', $source);

        $expected = VmString::explode(',', 'a,b,c', 2);
        $ht = ExplodeJitHelper::explodeArgv(',', 'a,b,c', 2);
        $this->assertInstanceOf(HashTable::class, $ht);
        $actual = [];
        foreach ($ht->iterate(true) as $value) {
            $actual[] = $value->toString();
        }
        $this->assertSame($expected, $actual);
    }

    public function testSpineBundleIncludesExplodeJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ExplodeJitHelper.php', $spine);
        $this->assertStringContainsString('StringExplode.php', $spine);
    }
}
