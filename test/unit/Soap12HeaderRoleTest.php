<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SoapClient SOAP 1.2 Header role/mustUnderstand (#31920).
 *
 * @covers issue #31920
 */
final class Soap12HeaderRoleTest extends TestCase
{
    public function testSoap12HeaderRoleAndSoap11Actor(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required to advertise SoapClient');
        }

        $root = dirname(__DIR__, 2);
        $script = $root.'/test/repro/issue_soap12_header_role.php';
        $this->assertFileExists($script);

        $php = \PHP_BINARY;
        $vm = $root.'/bin/vm.php';
        $cmd = \escapeshellarg($php).' '.\escapeshellarg($vm).' '.\escapeshellarg($script).' 2>&1';
        $out = \shell_exec($cmd);
        $this->assertIsString($out);
        $this->assertSame(
            "role=1\n"
            ."no_actor=1\n"
            ."mu_true=1\n"
            ."no_mu_1=1\n"
            ."role_next=1\n"
            ."actor11=1\n"
            ."no_role11=1\n"
            ."mu1_11=1\n",
            $out
        );
    }
}
