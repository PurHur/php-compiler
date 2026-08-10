<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * sort-family null $flags — Z_PARAM_LONG soft-null DEP under PROFILE=8.4 (#29385).
 *
 * php-src: ext/standard/array.c PHP_FUNCTION(sort) / Z_PARAM_LONG
 *
 * AOT fixture: test/fixtures/aot/cases/sort_null_flags.phpt (sort/rsort/ksort/krsort;
 * asort/arsort AOT packed-list is a pre-existing gap independent of null flags).
 */
final class SortNullFlagsSoftNullTest extends TestCase
{
    public function testVmDepThenCoerceUnderProfile84(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame($this->expectedDepOutput(), $out);
    }

    public function testJitDepThenCoerceUnderProfile84(): void
    {
        $out = $this->runBin('bin/jit.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame($this->expectedDepOutput(), $out);
    }

    public function testVmStrictTypesTypeErrorUnderProfile84(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "TypeError\n"
            ."sort(): Argument #2 (\$flags) must be of type int, null given\n",
            $out
        );
    }

    private function expectedDepOutput(): string
    {
        return "ERR[8192]: sort(): Passing null to parameter #2 (\$flags) of type int is deprecated\n"
            ."true\n"
            ."1,2,3\n"
            ."ERR[8192]: rsort(): Passing null to parameter #2 (\$flags) of type int is deprecated\n"
            ."rsort:true:3,2,1\n"
            ."ERR[8192]: asort(): Passing null to parameter #2 (\$flags) of type int is deprecated\n"
            ."asort:true:1,2,3\n"
            ."ERR[8192]: arsort(): Passing null to parameter #2 (\$flags) of type int is deprecated\n"
            ."arsort:true:3,2,1\n"
            ."ERR[8192]: ksort(): Passing null to parameter #2 (\$flags) of type int is deprecated\n"
            ."ksort:true:3,1,2\n"
            ."ERR[8192]: krsort(): Passing null to parameter #2 (\$flags) of type int is deprecated\n"
            ."krsort:true:2,1,3\n";
    }

    private function probeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
$a = [3, 1, 2];
$r = sort($a, null);
echo var_export($r, true), "\n";
echo implode(',', $a), "\n";
foreach (['rsort', 'asort', 'arsort', 'ksort', 'krsort'] as $fn) {
    $b = [3, 1, 2];
    $r = $fn($b, null);
    echo "$fn:", var_export($r, true), ':', implode(',', $b), "\n";
}
PHP;
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
declare(strict_types=1);
error_reporting(E_ALL);
try {
    $a = [3, 1, 2];
    sort($a, null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
PHP;
    }

    /**
     * @param array<string, string> $extraEnv
     */
    private function runBin(string $bin, string $code, array $extraEnv): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_sort_null_flags_');
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
