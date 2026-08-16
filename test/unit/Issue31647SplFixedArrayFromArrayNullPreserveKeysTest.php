<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplFixedArray::fromArray(null $preserveKeys) — soft-null E_DEPRECATED (#31647).
 *
 * php-src: ext/spl/spl_fixedarray.c — zim_SplFixedArray_fromArray / Z_PARAM_BOOL
 */
final class Issue31647SplFixedArrayFromArrayNullPreserveKeysTest extends TestCase
{
    public function testVmNullPreserveKeysDeprecation(): void
    {
        $out = $this->runBin('bin/vm.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testJitNullPreserveKeysDeprecation(): void
    {
        $out = $this->runBin('bin/jit.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testVmStrictTypesNullPreserveKeysTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: SplFixedArray::fromArray(): Argument #2 (\$preserveKeys) must be of type bool, null given\n",
            $out
        );
    }

    private function expectedSoftOutput(): string
    {
        return "DEP:SplFixedArray::fromArray(): Passing null to parameter #2 (\$preserveKeys) of type bool is deprecated\n"
            ."[1,2]\n";
    }

    private function softProbeCode(): string
    {
        return <<<'PHP'
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    if ($errno === E_DEPRECATED) {
        echo 'DEP:', $errstr, "\n";
        return true;
    }
    return false;
});
echo json_encode(iterator_to_array(SplFixedArray::fromArray([1, 2], null))), "\n";
PHP;
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
    SplFixedArray::fromArray([1, 2], null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31647_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $code);
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
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, $stdout.$stderr);

        return $stdout;
    }
}
