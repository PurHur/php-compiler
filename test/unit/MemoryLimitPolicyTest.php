<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Repository policy: unlimited PHP memory (memory_limit=-1) must not be used.
 */
final class MemoryLimitPolicyTest extends TestCase
{
    public function testCliRejectsUnlimitedMemoryLimitEnv(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $php = getenv('PHP_COMPILER_PHP') ?: 'php';
        $cmd = sprintf(
            '%s -d display_errors=0 %s -r %s 2>&1',
            escapeshellarg($php),
            escapeshellarg($repoRoot.'/src/cli.php'),
            escapeshellarg('exit(0);')
        );
        $fullEnv = getenv();
        $env = is_array($fullEnv) ? $fullEnv : [];
        $env['PHP_COMPILER_MEMORY_LIMIT'] = '-1';

        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            ['env', 'PHP_COMPILER_MEMORY_LIMIT=-1', $php, $repoRoot.'/src/cli.php', '-r', 'echo 1;'],
            $descriptorSpec,
            $pipes,
            $repoRoot
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('not allowed', $stderr);
    }
}
