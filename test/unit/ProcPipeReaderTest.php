<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Cli\PhpcBuild;
use PHPCompiler\Cli\ProcPipeReader;
use PHPUnit\Framework\TestCase;

/**
 * proc_open pipe multiplexing for phpc build/run (#36251).
 */
final class ProcPipeReaderTest extends TestCase
{
    public function testReadUntilProcessExitDrainsLargeStderrWithoutDeadlock(): void
    {
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $child = 'fwrite(STDERR, str_repeat("x", 200000)); echo "ok";';
        $proc = proc_open(array_merge(self::phpCommand(), ['-r', $child]), $desc, $pipes);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $captured = ProcPipeReader::readUntilProcessExit($proc, $pipes[1], $pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $this->assertSame(0, $captured['exitcode']);
        $this->assertSame('ok', $captured['stdout']);
        $this->assertSame(200_000, strlen($captured['stderr']));
    }

    public function testPhpcBuildRunCompileDrainsLargeStderrWithoutDeadlock(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $child = 'fwrite(STDERR, str_repeat("y", 200000)); echo "done";';
        $result = PhpcBuild::runCompile(
            self::phpCommand(),
            $repoRoot,
            '-r',
            $repoRoot,
            [$child]
        );

        $this->assertSame(0, $result['exit']);
        $this->assertSame('done', $result['stdout']);
        $this->assertSame(200_000, strlen($result['stderr']));
    }

    /**
     * @return list<string>
     */
    private static function phpCommand(): array
    {
        $phpEnv = getenv('PHP_COMPILER_PHP');
        if (false !== $phpEnv && '' !== $phpEnv) {
            return preg_split('/\s+/', $phpEnv) ?: [PHP_BINARY];
        }

        return [PHP_BINARY];
    }
}
