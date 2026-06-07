<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmMemory;
use PHPUnit\Framework\TestCase;

/** VmMemory RSS path without host getrusage() delegation (issue #7287, #4862). */
final class VmMemoryTest extends TestCase
{
    public function testSourceDoesNotDelegateToHostGetrusage(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmMemory.php');
        $this->assertDoesNotMatchRegularExpression('/function_exists\\s*\\(\\s*[\'"]getrusage/', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\getrusage\\s*\\(/', $source);
        $this->assertStringContainsString('/proc/self/statm', $source);
    }

    public function testRealUsageReadsProcStatmOnLinux(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY || !is_readable('/proc/self/statm')) {
            $this->markTestSkipped('Linux /proc/self/statm required for RSS probe');
        }

        $this->assertGreaterThan(0, VmMemory::getUsage(true));
    }
}
