<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** explode() runtime uses JitExplode LLVM; const-fold uses VmString::explode (#14750 / #27660). */
final class JitExplodeRuntimeShrinkTest extends TestCase
{
    public function testJitExplodeRuntimeEmitAndConstFold(): void
    {
        $explodeBuiltin = (string) file_get_contents(__DIR__.'/../../ext/standard/explode.php');
        $this->assertStringContainsString('StringExplode::invoke', $explodeBuiltin);
        $this->assertStringNotContainsString('JitExplode::explode(', $explodeBuiltin);

        $jitExplode = (string) file_get_contents(__DIR__.'/../../ext/standard/JitExplode.php');
        $this->assertStringContainsString('VmString::explode', $jitExplode);
        $this->assertStringContainsString('phpc_explode_find_delim', $jitExplode);
        $this->assertStringContainsString('VmStringCompare::findOffset', $jitExplode);
        $this->assertStringContainsString('function explode(', $jitExplode);
        $this->assertStringNotContainsString('JitStringSearch::findOffsetI32', $jitExplode);
    }
}
