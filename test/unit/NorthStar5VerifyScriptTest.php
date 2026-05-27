<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * make north-star5-verify / script/north-star5-verify.sh (issue #1416).
 */
final class NorthStar5VerifyScriptTest extends TestCase
{
    public function testNorthStar5VerifyScriptExistsAndPrintsHelp(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/script/north-star5-verify.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['bash', $script, '--help'], $descriptorSpec, $pipes, $repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $combined = trim(($stdout !== false ? $stdout : '')."\n".($stderr !== false ? $stderr : ''));
        $this->assertSame(0, $exit, $combined);
        $this->assertStringContainsString('north-star5-verify', $combined);
        $this->assertStringContainsString('bootstrap-vendor-objects.php', $combined);
        $this->assertStringContainsString('bootstrap-spine-count.php', $combined);
        $this->assertStringContainsString('718/718', $combined);
        $this->assertStringContainsString('--strict', $combined);
        $this->assertStringContainsString('#1416', $combined);
        $this->assertStringContainsString('#1492', $combined);
    }

    public function testNorthStar5VerifyScriptDocumentsSteps(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/north-star5-verify.sh');
        $this->assertStringContainsString('bootstrap-vendor-objects.php', $body);
        $this->assertStringContainsString('bootstrap-selfhost-probe', $body);
        $this->assertStringContainsString('PHP_COMPILER_VENDOR_PRELINK', $body);
        $this->assertStringContainsString('north-star5-verify: OK', $body);
    }

    public function testMakefileDeclaresNorthStar5VerifyTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('north-star5-verify:', $makefile);
        $this->assertStringContainsString('script/north-star5-verify.sh', $makefile);
    }
}
