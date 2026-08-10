<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * base_convert(null, …) soft-null DEP/TypeError cite parameter #1 ($num) under PROFILE=8.4 (#29320).
 *
 * php-src: ext/standard/math.c — PHP_FUNCTION(base_convert) Z_PARAM_STR for $num
 *
 * AOT fixture: test/fixtures/aot/cases/base_convert_null_num_forward84.phpt
 */
final class BaseConvertNullNumDepArgIndexTest extends TestCase
{
    public function testVmDepCitesParameterOneUnderProfile84(): void
    {
        $out = $this->runBin('bin/vm.php', $this->nullDepProbeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame($this->expectedNullDepOutput(), $out);
    }

    public function testJitDepCitesParameterOneUnderProfile84(): void
    {
        $out = $this->runBin('bin/jit.php', $this->nullDepProbeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame($this->expectedNullDepOutput(), $out);
    }

    public function testVmTypeErrorCitesParameterOneUnderProfile84(): void
    {
        $out = $this->runBin('bin/vm.php', $this->typeErrorProbeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame($this->expectedTypeErrorOutput(), $out);
    }

    private function expectedNullDepOutput(): string
    {
        return "ERR[8192]: base_convert(): Passing null to parameter #1 (\$num) of type string is deprecated\n"
            ."'0'\n";
    }

    private function expectedTypeErrorOutput(): string
    {
        return "TypeError: base_convert(): Argument #1 (\$num) must be of type string, array given\n";
    }

    private function nullDepProbeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
var_export(base_convert(null, 10, 16));
echo "\n";
PHP;
    }

    private function typeErrorProbeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
try {
    base_convert([], 10, 16);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
    }

    /**
     * @param array<string, string> $extraEnv
     */
    private function runBin(string $bin, string $code, array $extraEnv): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_base_convert_null_');
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
