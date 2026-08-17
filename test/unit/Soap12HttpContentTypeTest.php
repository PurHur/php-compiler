<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SoapClient SOAP 1.2 HTTP Content-Type / action (#31918).
 *
 * @covers issue #31918
 */
final class Soap12HttpContentTypeTest extends TestCase
{
    public function testSoap12ContentTypeAndSoap11Unchanged(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required to advertise SoapClient');
        }

        $root = dirname(__DIR__, 2);
        $script = $root.'/test/repro/issue_soap12_http_content_type.php';
        $this->assertFileExists($script);

        $php = \PHP_BINARY;
        $vm = $root.'/bin/vm.php';
        $cmd = \escapeshellarg($php).' '.\escapeshellarg($vm).' '.\escapeshellarg($script).' 2>&1';
        $out = \shell_exec($cmd);
        $this->assertIsString($out);
        $this->assertSame(
            "ct12=1\n"
            ."action=1\n"
            ."no_sa=1\n"
            ."no_text=1\n"
            ."ct11=1\n"
            ."sa11=1\n"
            ."no_soapxml=1\n",
            $out
        );
    }
}
