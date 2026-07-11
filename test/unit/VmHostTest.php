<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmHost;
use PHPUnit\Framework\TestCase;

/** Issue #5022: VM gethostname() must not delegate to host \\gethostname(). */
final class VmHostTest extends TestCase
{
    public function testVmHostDoesNotReferenceHostGethostname(): void
    {
        $source = file_get_contents(__DIR__.'/../../ext/standard/VmHost.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('@\\gethostname(', $source);
        $this->assertStringNotContainsString('= \\gethostname(', $source);
        $this->assertStringNotContainsString("function_exists('gethostname')", $source);
    }

    public function testGethostnameReturnsNonEmptyWhenHostnameSourcesAvailable(): void
    {
        if (!VmHost::available()) {
            $this->markTestSkipped('hostname sources unavailable on this host');
        }

        $host = VmHost::gethostname();
        $this->assertIsString($host);
        $this->assertNotSame('', $host);
    }
}
