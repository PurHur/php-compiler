<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GcDestructAllowDelrefJitHelper;
use PHPUnit\Framework\TestCase;

/** GcCollectCyclesRuntime routes destruct delref gate through GcDestructAllowDelrefJitHelper PHP (#15852). */
final class GcDestructAllowDelrefRuntimeShrinkTest extends TestCase
{
    public function testGcCollectCyclesRuntimeUsesDestructAllowDelrefJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('GcDestructAllowDelrefJitHelper', $source);
        $this->assertStringNotContainsString("addGlobal(\$i32, self::G_ALLOW_DELREF)", $source);
        $this->assertStringNotContainsString('implementAllowDelrefToggle', $source);
        $this->assertStringNotContainsString('destruct_delref_allowed_entry', $source);
    }

    public function testGcDestructAllowDelrefJitHelperRoundtrip(): void
    {
        GcDestructAllowDelrefJitHelper::resetForTest();
        $this->assertTrue(GcDestructAllowDelrefJitHelper::delrefAllowed());

        GcDestructAllowDelrefJitHelper::setAllowDelref(false);
        $this->assertFalse(GcDestructAllowDelrefJitHelper::delrefAllowed());

        GcDestructAllowDelrefJitHelper::setAllowDelref(true);
        $this->assertTrue(GcDestructAllowDelrefJitHelper::delrefAllowed());
    }
}
