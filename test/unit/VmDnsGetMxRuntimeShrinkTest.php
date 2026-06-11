<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmDns;
use PHPUnit\Framework\TestCase;

/** VmDns dns_get_mx must not delegate to host Zend DNS builtins (#4125). */
final class VmDnsGetMxRuntimeShrinkTest extends TestCase
{
    public function testVmDnsDoesNotReferenceHostDnsGetMx(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmDns.php');
        $this->assertStringNotContainsString('function_exists(\'dns_get_mx\')', $source);
        $this->assertStringNotContainsString('\\dns_get_mx(', $source);
        $this->assertStringNotContainsString('\\getmxrr(', $source);
    }

    public function testExampleComMxViaFfiWhenAvailable(): void
    {
        $result = VmDns::dnsGetMx('example.com');
        if (!$result['ok']) {
            $this->markTestSkipped('example.com MX unavailable via FFI getmxrr');
        }
        $this->assertGreaterThan(0, $result['hosts']->getNumElements());
        $this->assertSame(
            $result['hosts']->getNumElements(),
            $result['weights']->getNumElements()
        );
    }
}
