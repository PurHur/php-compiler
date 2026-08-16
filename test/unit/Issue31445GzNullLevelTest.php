<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * gzcompress/gzencode/gzdeflate(null $level) — soft-null DEP (#31445).
 *
 * php-src: ext/zlib/zlib.c PHP_FUNCTION(gzcompress|gzencode|gzdeflate)
 */
final class Issue31445GzNullLevelTest extends TestCase
{
    public function testVmSoftNullDepThenCompress(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode());
        $this->assertSame($this->expectedDepOutput(), $out);
    }

    public function testJitSoftNullDepThenCompress(): void
    {
        $out = $this->runBin('bin/jit.php', $this->probeCode());
        $this->assertSame($this->expectedDepOutput(), $out);
    }

    public function testVmStrictTypesTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: gzcompress(): Argument #2 (\$level) must be of type int, null given\n",
            $out
        );
    }

    public function testJitStrictTypesTypeError(): void
    {
        $out = $this->runBin('bin/jit.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: gzcompress(): Argument #2 (\$level) must be of type int, null given\n",
            $out
        );
    }

    private function expectedDepOutput(): string
    {
        return "ERR[8192]: gzcompress(): Passing null to parameter #2 (\$level) of type int is deprecated\n"
            ."gzcompress OK\n"
            ."ERR[8192]: gzencode(): Passing null to parameter #2 (\$level) of type int is deprecated\n"
            ."gzencode OK\n"
            ."ERR[8192]: gzdeflate(): Passing null to parameter #2 (\$level) of type int is deprecated\n"
            ."gzdeflate OK\n";
    }

    private function probeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
foreach (['gzcompress', 'gzencode', 'gzdeflate'] as $f) {
    $r = $f('a', null);
    echo $f, ' ', is_string($r) && strlen($r) > 0 ? 'OK' : 'BAD', "\n";
}
PHP;
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
declare(strict_types=1);
error_reporting(E_ALL);
try {
    gzcompress('a', null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31445_');
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
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, $stdout.$stderr);

        return $stdout;
    }
}
