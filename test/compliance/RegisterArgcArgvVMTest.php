<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** register_argc_argv startup INI gates CLI $argc/$argv (issue #4515). */
final class RegisterArgcArgvVMTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testRegisterArgcArgvZeroOmitsGlobals(): void
    {
        $script = self::$root.'/test/repro/maintainer_gap_register_argc_argv_zero.php';
        $cmd = [
            PHP_BINARY,
            '-d', 'display_errors=0',
            '-d', 'error_reporting=0',
            self::$root.'/bin/vm.php',
            '-d', 'register_argc_argv=0',
            $script,
            'a',
            'b',
        ];
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::$root
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim($stderr));
        $this->assertSame(
            "ini_get=0\nini_set=false\nargc='unset'\nargv_isset=false\n",
            $stdout
        );
    }
}
