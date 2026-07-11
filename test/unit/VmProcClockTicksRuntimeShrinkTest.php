<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** VmProcClockTicksPure — /proc/auxv CLK_TCK without libc sysconf FFI (#13522, php-in-PHP). */
final class VmProcClockTicksRuntimeShrinkTest extends TestCase
{
    public function testVmProcClockTicksPureHasNoLibcFfi(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/VmProcClockTicksPure.php');
        $this->assertStringContainsString('/proc/self/auxv', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
        $this->assertStringNotContainsString('readSysconfClkTck', $source);
        $this->assertStringNotContainsString('sysconfFfi', $source);
    }
}
