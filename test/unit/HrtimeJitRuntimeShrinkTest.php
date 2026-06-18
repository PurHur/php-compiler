<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** hrtime JIT helpers route through VmHrtimeNative PHP, not /proc/uptime LLVM (#9182). */
final class HrtimeJitRuntimeShrinkTest extends TestCase
{
    public function testHrtimeJitHelperDelegatesToVmHrtimeNative(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/HrtimeJitHelper.php');
        $this->assertStringContainsString('VmHrtimeNative::parseUptimeRaw', $source);
        $this->assertStringContainsString('/proc/uptime', $source);
    }

    public function testStringHrtimeNoLongerUsesMonotonicReadLlvm(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHrtime.php');
        $this->assertStringContainsString('StringHrtimeRuntime::ensureLinked', $source);
        $runtimeSource = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHrtimeRuntime.php');
        $this->assertStringContainsString('__hashtable__readLongAt', $runtimeSource);
    }
}
