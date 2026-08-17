<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SoapClient::__soapCall $inputHeaders per-call SoapHeader (#31874).
 *
 * @covers issue #31874
 */
final class SoapCallInputHeadersTest extends TestCase
{
    public function testPerCallInputHeaders(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required to advertise SoapClient');
        }

        $root = dirname(__DIR__, 2);
        $script = $root.'/test/repro/issue_soap_call_input_headers.php';
        $this->assertFileExists($script);

        $php = \PHP_BINARY;
        $vm = $root.'/bin/vm.php';
        $cmd = \escapeshellarg($php).' '.\escapeshellarg($vm).' '.\escapeshellarg($script).' 2>&1';
        $out = \shell_exec($cmd);
        $this->assertIsString($out);
        $this->assertSame(
            "input_header=1\n"
            ."must=1\n"
            ."not_sticky=0\n"
            ."merged=1\n"
            ."call_first=1\n"
            ."array_form=1\n",
            $out
        );
    }
}
