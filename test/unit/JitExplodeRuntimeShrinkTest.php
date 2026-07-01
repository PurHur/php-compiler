<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** explode() JIT runtime routes through ExplodeJitHelper/VmString (#14750); const-fold uses VmString::explode. */
final class JitExplodeRuntimeShrinkTest extends TestCase
{
    public function testJitExplodeRuntimeUsesExplodeJitHelperNotInlineLlvm(): void
    {
        $explodeBuiltin = (string) file_get_contents(__DIR__.'/../../ext/standard/explode.php');
        $this->assertStringContainsString('StringExplode::invoke', $explodeBuiltin);
        $this->assertStringNotContainsString('JitExplode::explode', $explodeBuiltin);

        $jitExplode = (string) file_get_contents(__DIR__.'/../../ext/standard/JitExplode.php');
        $this->assertStringContainsString('VmString::explode', $jitExplode);
        $this->assertStringNotContainsString('JitStringSearch::findOffsetI32', $jitExplode);
        $this->assertStringNotContainsString("lookupFunction('strstr')", $jitExplode);
    }
}
