<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SoapClient::__doRequest subclass dispatch + $oneWay (#31876).
 *
 * @covers issue #31876
 */
final class SoapDoRequestOverrideTest extends TestCase
{
    public function testSubclassDoRequestAndOneWay(): void
    {
        if (!\extension_loaded('soap')) {
            $this->markTestSkipped('host php-soap required to advertise SoapClient');
        }

        $root = dirname(__DIR__, 2);
        $script = $root.'/test/repro/issue_soap_dorequest_override_oneway.php';
        $this->assertFileExists($script);

        $php = \PHP_BINARY;
        $vm = $root.'/bin/vm.php';
        $cmd = \escapeshellarg($php).' '.\escapeshellarg($vm).' '.\escapeshellarg($script).' 2>&1';
        $raw = \shell_exec($cmd);
        $this->assertIsString($raw);
        $lines = [];
        foreach (\explode("\n", $raw) as $line) {
            if (\str_starts_with($line, 'PHP Deprecated:')) {
                continue;
            }
            $lines[] = $line;
        }
        $out = \implode("\n", $lines);
        $this->assertSame(
            "hits=1\n"
            ."default_oneway=false\n"
            ."oneway_empty=1\n"
            ."wait_returns_body=1\n",
            $out
        );
    }
}
