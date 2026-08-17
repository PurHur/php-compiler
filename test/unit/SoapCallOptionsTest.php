<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SoapClient::__soapCall $options location/soapaction (#31873).
 *
 * @covers issue #31873
 */
final class SoapCallOptionsTest extends TestCase
{
    public function testPerCallLocationAndSoapAction(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required to advertise SoapClient');
        }

        $root = dirname(__DIR__, 2);
        $script = $root.'/test/repro/issue_soap_call_options.php';
        $this->assertFileExists($script);

        $php = \PHP_BINARY;
        $vm = $root.'/bin/vm.php';
        $cmd = \escapeshellarg($php).' '.\escapeshellarg($vm).' '.\escapeshellarg($script).' 2>&1';
        $out = \shell_exec($cmd);
        $this->assertIsString($out);
        $this->assertSame(
            "params=name:string,args:array,options:?array=,inputHeaders:=,outputHeaders:=\n"
            ."opt_loc=hello\n"
            ."ctor_location_sticky=1\n"
            ."named_opt_loc=hello\n"
            ."custom_action=1\n"
            ."custom_uri=1\n",
            $out
        );
    }
}
