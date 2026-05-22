<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Cli\InvokeCwd;
use PHPUnit\Framework\TestCase;

/**
 * phpc resolves project paths against PHPC_INVOKE_CWD (issue #699).
 */
final class PhpcInvokeCwdTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHPC_INVOKE_CWD');
        parent::tearDown();
    }

    public function testResolveRelativeAgainstInvokeCwd(): void
    {
        $base = sys_get_temp_dir().'/phpc_invoke_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($base));
        try {
            putenv('PHPC_INVOKE_CWD='.$base);
            $resolved = InvokeCwd::resolve('.');
            $this->assertSame(realpath($base), $resolved);
            $nested = $base.'/nested';
            mkdir($nested);
            $this->assertSame(realpath($nested), InvokeCwd::resolve('nested'));
        } finally {
            if (is_dir($base.'/nested')) {
                rmdir($base.'/nested');
            }
            if (is_dir($base)) {
                rmdir($base);
            }
        }
    }

    public function testBuildProjectDotFromMiniWebAppDirectory(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $miniweb = $repoRoot.'/examples/003-MiniWebApp';
        if (!is_file($miniweb.'/phpc.json')) {
            $this->markTestSkipped('examples/003-MiniWebApp missing');
        }
        $phpc = $repoRoot.'/phpc';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [$phpc, 'build', '--project', '.', '--dry-run'],
            $descriptorSpec,
            $pipes,
            $miniweb
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $combined = (false !== $stdout ? $stdout : '').(false !== $stderr ? $stderr : '');
        $this->assertSame(
            0,
            $exit,
            'phpc build --project . should find phpc.json via PHPC_INVOKE_CWD: '.$combined
        );
        $this->assertStringContainsString('public/index.php', $combined);
    }
}
