<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../support/HarnessHostPhpGuard.php';

final class HarnessHostPhpGuardTest extends TestCase
{
    public function testParseMemoryLimitBytes(): void
    {
        $this->assertSame(1536 * 1024 * 1024, \HarnessHostPhpGuard::parseMemoryLimitBytes('1536M'));
        $this->assertSame(4096 * 1024 * 1024, \HarnessHostPhpGuard::parseMemoryLimitBytes('4096M'));
        $this->assertSame(-1, \HarnessHostPhpGuard::parseMemoryLimitBytes('-1'));
        $this->assertSame(-1, \HarnessHostPhpGuard::parseMemoryLimitBytes(''));
    }

    public function testPhpunitScriptUsesDockerExecWithPhpEnv(): void
    {
        $root = dirname(__DIR__, 2);
        $body = (string) file_get_contents($root.'/script/phpunit.sh');
        $this->assertStringContainsString('docker-exec.sh', $body);
        $this->assertStringContainsString('php-env.sh', $body);
        $this->assertStringContainsString('vendor/bin/phpunit', $body);
    }

    public function testBootstrapLoadsHarnessHostPhpGuard(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__).'/bootstrap.php');
        $this->assertStringContainsString('HarnessHostPhpGuard.php', $body);
        $this->assertStringContainsString('refuseBarePhpunitOnHarnessHost', $body);
    }
}
