<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * range(null, …) $start/$end — Zend soft-null DEP then coerce (#29348).
 *
 * php-src: ext/standard/array.c PHP_FUNCTION(range) / Z_PARAM_STR_OR_LONG
 *
 * VMTest data-provider is currently blocked by unrelated --EXTENSIONS-- cases;
 * this unit guard runs the issue repro via bin/vm.php + bin/jit.php.
 * AOT fixture: test/fixtures/aot/cases/range_null_endpoint.phpt
 */
final class RangeNullEndpointSoftNullTest extends TestCase
{
    public function testVmDepThenCoerceUnderProfile84(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "ERR[8192]: range(): Passing null to parameter #1 (\$start) of type string|int|float is deprecated\n"
            ."array (\n"
            ."  0 => 0,\n"
            ."  1 => 1,\n"
            ."  2 => 2,\n"
            ."  3 => 3,\n"
            .")\n"
            ."ERR[8192]: range(): Passing null to parameter #2 (\$end) of type string|int|float is deprecated\n"
            ."array (\n"
            ."  0 => 0,\n"
            .")\n",
            $out
        );
    }

    public function testJitDepThenCoerceUnderProfile84(): void
    {
        $out = $this->runBin('bin/jit.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "ERR[8192]: range(): Passing null to parameter #1 (\$start) of type string|int|float is deprecated\n"
            ."array (\n"
            ."  0 => 0,\n"
            ."  1 => 1,\n"
            ."  2 => 2,\n"
            ."  3 => 3,\n"
            .")\n"
            ."ERR[8192]: range(): Passing null to parameter #2 (\$end) of type string|int|float is deprecated\n"
            ."array (\n"
            ."  0 => 0,\n"
            .")\n",
            $out
        );
    }

    public function testVmStrictTypesTypeErrorUnderProfile84(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "TypeError\n"
            ."range(): Argument #1 (\$start) must be of type string|int|float, null given\n",
            $out
        );
    }

    /** Default / Zend 8.2 profile: untyped $start — coerce under strict_types (#29767). */
    public function testVmStrictTypesCoerceUnderDefaultProfile(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode(), ['PHP_COMPILER_PROFILE' => '']);
        $this->assertSame("ok\n", $out);
    }

    public function testJitStrictTypesCoerceUnderDefaultProfile(): void
    {
        $out = $this->runBin('bin/jit.php', $this->strictProbeCode(), ['PHP_COMPILER_PROFILE' => '']);
        $this->assertSame("ok\n", $out);
    }

    private function probeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
var_export(range(null, 3));
echo "\n";
var_export(range(0, null));
echo "\n";
PHP;
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
declare(strict_types=1);
error_reporting(E_ALL);
try {
    $r = range(null, 3);
    echo ($r === [0, 1, 2, 3]) ? "ok\n" : "bad\n";
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
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_range_null_ep_');
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
