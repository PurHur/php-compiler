<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Bootstrap parser/type patches: Reference decl + nullsafe CFG ops (issues #58, #1056, #1086).
 */
final class BootstrapParserReferenceTest extends TestCase
{
    public function testReferenceReturnTypeLint(): void
    {
        $this->assertSame(0, $this->lint('test/bootstrap-aot/const_string_folder_magic_dir.php'));
    }

    public function testNullsafeMethodCallLint(): void
    {
        $this->assertSame(0, $this->lint('test/bootstrap-aot/nullsafe_method_call.php'));
    }

    public function testNullsafePropertyAcceptedByLintCli(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = [PHP_BINARY, $root.'/bin/lint.php', '-r', '<?php class C { public int $x = 1; } $c = null; $c?->x;'];
        $this->assertSame(0, $this->runCommand($cmd, $root));
    }

    private function lint(string $rel): int
    {
        $root = dirname(__DIR__, 2);

        return $this->lintFile($root.'/'.$rel);
    }

    private function lintFile(string $path): int
    {
        exec(
            sprintf(
                '%s %s/bin/compile.php -l %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(dirname(__DIR__, 2)),
                escapeshellarg($path)
            ),
            $out,
            $code
        );
        if (0 !== $code) {
            self::fail(implode("\n", $out));
        }

        return $code;
    }

    /**
     * @param list<string> $cmd
     */
    private function runCommand(array $cmd, string $cwd): int
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if (0 !== $code) {
            self::fail(trim(($stderr !== false ? $stderr : '')."\n".($stdout !== false ? $stdout : '')));
        }

        return $code;
    }
}
