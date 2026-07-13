<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** CLI display_errors stdout copy mirrors Zend short prefix (#18562, sapi/cli/php_cli.c). */
final class CliWarningDisplayErrorsTest extends TestCase
{
    public function testHex2binWarningStderrAndStdoutPrefixes(): void
    {
        $repo = dirname(__DIR__, 2);
        $proc = proc_open(
            [
                PHP_BINARY,
                $repo.'/bin/vm.php',
                '-d',
                'display_errors=1',
                '-r',
                'var_dump(hex2bin("a"));',
            ],
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
        $this->assertMatchesRegularExpression(
            '/PHP Warning:\s+hex2bin\(\): Hexadecimal input string must have an even length/',
            (string) $stderr
        );
        $this->assertMatchesRegularExpression(
            '/^Warning: hex2bin\(\): Hexadecimal input string must have an even length/m',
            (string) $stdout
        );
        $this->assertStringNotContainsString('PHP Warning:', (string) $stdout);
        $this->assertStringContainsString('bool(false)', (string) $stdout);
    }
}
