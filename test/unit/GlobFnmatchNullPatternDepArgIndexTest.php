<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * glob()/fnmatch() null $pattern — DEP cites parameter #1 under PROFILE=8.4 (#29659, #29660).
 *
 * php-src: ext/standard/file.c PHP_FUNCTION(glob); ext/standard/fnmatch.c PHP_FUNCTION(fnmatch)
 *
 * AOT fixture: test/fixtures/aot/cases/glob_fnmatch_null_forward84.phpt
 */
final class GlobFnmatchNullPatternDepArgIndexTest extends TestCase
{
    public function testVmDepCitesParameterOneUnderProfile84(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame($this->expectedDepOutput(), $out);
    }

    public function testJitDepCitesParameterOneUnderProfile84(): void
    {
        $out = $this->runBin('bin/jit.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame($this->expectedDepOutput(), $out);
    }

    private function expectedDepOutput(): string
    {
        return "ERR[8192]: glob(): Passing null to parameter #1 (\$pattern) of type string is deprecated\n"
            ."glob:array (\n)\n"
            ."ERR[8192]: fnmatch(): Passing null to parameter #1 (\$pattern) of type string is deprecated\n"
            ."fnmatch:false\n";
    }

    private function probeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
$g = glob(null);
echo 'glob:', var_export($g, true), "\n";
$f = fnmatch(null, 'a');
echo 'fnmatch:', var_export($f, true), "\n";
PHP;
    }

    /**
     * @param array<string, string> $extraEnv
     */
    private function runBin(string $bin, string $code, array $extraEnv): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_glob_fnmatch_null_');
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
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            ['php', $repo.'/'.$bin, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, (string) $err.(string) $out);

        return (string) $out;
    }
}
