<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SoapClient SOAP 1.2 encodingStyle URI (#31919).
 *
 * @covers issue #31919
 */
final class Soap12EncodingStyleTest extends TestCase
{
    public function testSoap12EncodingStyleUriAndSoap11Unchanged(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required to advertise SoapClient');
        }

        $root = dirname(__DIR__, 2);
        $script = $root.'/test/repro/issue_soap12_encodingstyle.php';
        $this->assertFileExists($script);

        $php = \PHP_BINARY;
        $vm = $root.'/bin/vm.php';
        $cmd = \escapeshellarg($php).' '.\escapeshellarg($vm).' '.\escapeshellarg($script).' 2>&1';
        $out = \shell_exec($cmd);
        $this->assertIsString($out);
        $this->assertSame(
            "enc12=1\n"
            ."no_enc11=1\n"
            ."env_prefix=1\n"
            ."no_soapenv=1\n"
            ."enc11=1\n"
            ."no_enc12=1\n"
            ."soapenv=1\n",
            $out
        );
    }
}
