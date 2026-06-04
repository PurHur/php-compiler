<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmHrtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5174 */
final class VmHrtimeTest extends TestCase
{
    public function testMonotonicNanosecondsAndPair(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('FFI extension required for VmHrtime');
        }
        $a = VmHrtime::hrtime(true);
        $b = VmHrtime::hrtime(true);
        $this->assertGreaterThan(0, $a);
        $this->assertGreaterThanOrEqual($a, $b);

        $pair = VmHrtime::hrtime(false);
        $this->assertIsArray($pair);
        $this->assertCount(2, $pair);
        $this->assertGreaterThanOrEqual(0, $pair[0]);
        $this->assertGreaterThanOrEqual(0, $pair[1]);
    }
}
