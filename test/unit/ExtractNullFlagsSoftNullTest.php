<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * extract() null $flags — Z_PARAM_LONG soft-null DEP (#31194).
 *
 * php-src: ext/standard/array.c PHP_FUNCTION(extract) / int $flags
 *
 * AOT fixture: test/fixtures/aot/cases/extract_null_flags.phpt
 * (DEP skipped on user-script AOT fold — #21593; coerce + import still apply).
 */
final class ExtractNullFlagsSoftNullTest extends TestCase
{
    public function testVmDepThenCoerce(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode());
        $this->assertSame($this->expectedDepOutput(), $out);
    }

    public function testJitDepThenCoerce(): void
    {
        $out = $this->runBin('bin/jit.php', $this->probeCode());
        $this->assertSame($this->expectedDepOutput(), $out);
    }

    public function testVmStrictTypesTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError\n"
            ."extract(): Argument #2 (\$flags) must be of type int, null given\n",
            $out
        );
    }

    public function testVmArrayFlagsTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->arrayFlagsProbeCode());
        $this->assertSame(
            "TypeError:extract(): Argument #2 (\$flags) must be of type int, array given\n",
            $out
        );
    }

    private function expectedDepOutput(): string
    {
        return "ERR[8192]: extract(): Passing null to parameter #2 (\$flags) of type int is deprecated\n"
            ."1\n"
            ."a=1\n";
    }

    private function probeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
$arr = ['a' => 1];
$n = extract($arr, null);
var_export($n);
echo "\n";
echo 'a=', $a ?? 'undef', "\n";
PHP;
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
declare(strict_types=1);
error_reporting(E_ALL);
try {
    $arr = ['a' => 1];
    extract($arr, null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
PHP;
    }

    private function arrayFlagsProbeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
$src = ['b' => 2];
$bad = [];
try {
    extract($src, $bad);
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_extract_null_flags_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $env = $_ENV;
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
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $code, $stdout.$stderr);

        return $stdout;
    }
}
