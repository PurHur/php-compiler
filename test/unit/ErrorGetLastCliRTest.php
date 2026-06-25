<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** error_get_last() location for php -r / bin/vm.php -r (issue #11533). */
final class ErrorGetLastCliRTest extends TestCase
{
    public function testErrorGetLastReportsCommandLineCodeForDashR(): void
    {
        $root = realpath(__DIR__.'/../..');
        self::assertNotFalse($root);
        $vm = $root.'/bin/vm.php';
        $code = 'trigger_error("probe", E_USER_WARNING); var_export(error_get_last());';
        $proc = proc_open(
            [PHP_BINARY, $vm, '-r', $code],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        self::assertSame(0, $exit, $stderr);
        self::assertStringContainsString("'file' => 'Command line code'", $stdout);
        self::assertStringContainsString("'line' => 1", $stdout);
    }
}
