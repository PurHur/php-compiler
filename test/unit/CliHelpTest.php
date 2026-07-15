<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** bin/* --help/-h prints usage and exits 0 (issue #18690, sapi/cli/php_cli.c). */
final class CliHelpTest extends TestCase
{
    /**
     * @dataProvider helpFlagProvider
     */
    public function testVmHelpPrintsUsageAndExitsZero(string $flag): void
    {
        $repo = dirname(__DIR__, 2);
        $proc = proc_open(
            [PHP_BINARY, $repo.'/bin/vm.php', $flag],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertSame(0, $exit);
        $this->assertSame('', (string) $stderr);
        $this->assertStringContainsString('Usage: vm.php', (string) $stdout);
        $this->assertStringContainsString('-h --help', (string) $stdout);
        $this->assertStringContainsString('-r <code>', (string) $stdout);
        $this->assertStringContainsString('--include <file>', (string) $stdout);
        $this->assertStringContainsString('--no-cache', (string) $stdout);
    }

    /** @return array<string, array{0: string}> */
    public function helpFlagProvider(): array
    {
        return [
            'long help' => ['--help'],
            'short help' => ['-h'],
        ];
    }
}
