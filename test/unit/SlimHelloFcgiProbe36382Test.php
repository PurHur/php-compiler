<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Guard Slim hello FastCGI smoke (#36382 Done-when under phpc fcgi).
 */
final class SlimHelloFcgiProbe36382Test extends TestCase
{
    public function testProbeScriptUsesInTreeFastCgiFraming(): void
    {
        $path = dirname(__DIR__, 2).'/script/composer/slim-hello-fcgi-probe.php';
        $this->assertFileExists($path);
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString('PHPCompiler\\Web\\FastCgi\\Request', $body);
        $this->assertStringContainsString('PHPCompiler\\Web\\FastCgi\\Record', $body);
        $this->assertStringContainsString('Request::encode', $body);
        $this->assertStringContainsString('Record::readFromStream', $body);
        $this->assertStringContainsString('--connect', $body);
        $this->assertStringContainsString('REQUEST_URI', $body);
        $this->assertStringContainsString('#36382', $body);
    }

    public function testSmokePrefersInTreeProbeOverCgiOnlyGreenwash(): void
    {
        $path = dirname(__DIR__, 2).'/script/composer/slim-hello-36382-smoke.sh';
        $this->assertFileExists($path);
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString('slim-hello-fcgi-probe.php', $body);
        $this->assertStringContainsString('FastCGI hello OK', $body);
        $this->assertStringContainsString('CGI hello OK but FastCGI probe did not return hello', $body);
        $this->assertStringContainsString('phpc.json', $body);
        // Must not soft-pass on CGI-only (old gate printed "CGI hello OK" and exited 0).
        $this->assertDoesNotMatchRegularExpression('/echo "slim-hello-36382-smoke: CGI hello OK"/', $body);
    }
}
