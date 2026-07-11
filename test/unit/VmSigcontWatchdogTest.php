<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../BaseTest.php';

final class VmSigcontWatchdogTest extends TestCase
{
    public function testStoppedChildIsContinued(): void
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('posix extension required to send SIGSTOP');
        }

        $cmd = [
            PHP_BINARY,
            '-r',
            'posix_kill(getmypid(), SIGSTOP); echo "ok\n";',
        ];

        [$stdout, $exitCode, $stderr] = VmSigcontHarness::runSubprocess($cmd, getcwd() ?: __DIR__, $_ENV, null, __METHOD__);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertSame("ok\n", $stdout);
    }
}

/**
 * Test harness to access BaseTest::runVmSubprocess().
 */
final class VmSigcontHarness extends BaseTest
{
    /**
     * @param list<string> $cmd
     * @param array<string, string> $env
     * @return array{0: string, 1: int, 2: string}
     */
    public static function runSubprocess(array $cmd, string $cwd, array $env, ?string $stdin, string $testName): array
    {
        return self::runVmSubprocess($cmd, $cwd, $env, $stdin, $testName);
    }
}

