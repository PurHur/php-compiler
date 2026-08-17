<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SoapServer SOAP 1.2 success envelope (#31921).
 *
 * @covers issue #31921
 */
final class Soap12ServerResponseTest extends TestCase
{
    public function testSoap12SuccessEnvelope(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required to advertise SoapServer');
        }

        $root = dirname(__DIR__, 2);
        $script = $root.'/test/repro/issue_soap12_server_response.php';
        $this->assertFileExists($script);

        $php = \PHP_BINARY;
        $vm = $root.'/bin/vm.php';
        $cmd = \escapeshellarg($php).' '.\escapeshellarg($vm).' '.\escapeshellarg($script).' 2>&1';
        $out = \shell_exec($cmd);
        $this->assertIsString($out);
        $this->assertSame(
            "ENV12=1\n"
            ."ENV11=0\n"
            ."ENC12=1\n"
            ."ENC11=0\n"
            ."env_prefix=1\n"
            ."11_env=1\n"
            ."11_enc=1\n",
            $out
        );
    }
}
