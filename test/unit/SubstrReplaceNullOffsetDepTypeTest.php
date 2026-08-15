<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * substr_replace(…, null, …) $offset — Zend soft-null DEP type array|int (#29396).
 *
 * php-src: ext/standard/string.c / string.stub.php — array|int $offset
 */
final class SubstrReplaceNullOffsetDepTypeTest extends TestCase
{
    public function testVmDepTypeArrayIntUnderProfile84(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "DEP:substr_replace(): Passing null to parameter #3 (\$offset) of type array|int is deprecated\n"
            ."Xbcdef\n",
            $out
        );
    }

    public function testJitDepTypeArrayIntUnderProfile84(): void
    {
        $out = $this->runBin('bin/jit.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "DEP:substr_replace(): Passing null to parameter #3 (\$offset) of type array|int is deprecated\n"
            ."Xbcdef\n",
            $out
        );
    }

    private function probeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    if (E_DEPRECATED === $errno) {
        echo 'DEP:', $errstr, "\n";

        return true;
    }

    return false;
});
echo substr_replace('abcdef', 'X', null, 1), "\n";
PHP;
    }

    /**
     * @param array<string, string> $extraEnv
     */
    private function runBin(string $bin, string $code, array $extraEnv): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_sr_null_off_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $env = $_ENV;
        foreach ($extraEnv as $k => $v) {
            if ('' === $v) {
                unset($env[$k]);
            } else {
                $env[$k] = $v;
            }
        }
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($repo.'/'.$bin).' '.escapeshellarg($tmp);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $status, "stdout=\n{$stdout}\nstderr=\n{$stderr}");

        return (string) $stdout;
    }
}
