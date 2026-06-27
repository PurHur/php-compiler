<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmDns;
use PHPUnit\Framework\TestCase;

/** VmDns checkdnsrr without host \\checkdnsrr() delegation (#7934, #7315 phase 2). */
final class VmDnsRuntimeShrinkTest extends TestCase
{
    public function testVmDnsDoesNotReferenceHostCheckdnsrr(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmDns.php');
        $this->assertStringContainsString('checkdnsrrPurePhp', $source);
        $this->assertStringNotContainsString('hostCheckdnsrr', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\checkdnsrr\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression("/function_exists\\('checkdnsrr'\\)/", $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/VmDnsGetaddrinfo.php');
    }

    public function testVmDnsDoesNotDelegateEtcHostsReadsToHostFile(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmDns.php');
        $this->assertStringContainsString('VmFs::file', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\file\\s*\\(/', $source);
    }

    public function testCheckdnsrrLocalhostARecordViaEtcHosts(): void
    {
        $this->assertTrue(VmDns::checkdnsrr('localhost', 'A'));
    }

    public function testCheckdnsrrMxWithoutFfiReturnsFalseForUnknownHost(): void
    {
        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertFalse(VmDns::checkdnsrr('no-such-phpc-host.invalid.', 'MX'));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }

    public function testGethostbynamelLocalhostDuplicateARecordsWithoutFfi(): void
    {
        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $ips = VmDns::gethostbynamel('localhost');
            $this->assertNotFalse($ips);
            $this->assertSame(2, $ips->getNumElements());
            $this->assertSame('127.0.0.1', $ips->find('0')?->toString());
            $this->assertSame('127.0.0.1', $ips->find('1')?->toString());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
