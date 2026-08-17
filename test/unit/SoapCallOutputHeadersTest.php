<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SoapClient::__soapCall &$outputHeaders SOAP Header children (#31875).
 *
 * @covers issue #31875
 */
final class SoapCallOutputHeadersTest extends TestCase
{
    public function testOutputHeadersByRefAndHeaderChildren(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required to advertise SoapClient');
        }

        $root = dirname(__DIR__, 2);
        $script = $root.'/test/repro/issue_soap_call_output_headers.php';
        $this->assertFileExists($script);

        $php = \PHP_BINARY;
        $vm = $root.'/bin/vm.php';
        $cmd = \escapeshellarg($php).' '.\escapeshellarg($vm).' '.\escapeshellarg($script).' 2>&1';
        $out = \shell_exec($cmd);
        $this->assertIsString($out);
        $this->assertSame(
            "out_name=outputHeaders\n"
            ."out_byref=1\n"
            ."cleared=1\n"
            ."keys=\n"
            ."body=hello\n"
            ."Token=secret\n"
            ."named_cleared=1\n",
            $out
        );
    }
}
