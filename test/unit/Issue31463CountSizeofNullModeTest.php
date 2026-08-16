<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * count/sizeof(..., null) — soft-null DEP for $mode (#31463).
 *
 * php-src: Zend/zend_builtin_functions.c PHP_FUNCTION(count)
 */
final class Issue31463CountSizeofNullModeTest extends TestCase
{
    public function testVmSoftNullDepAndCount(): void
    {
        $out = $this->runBin('bin/vm.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testJitSoftNullDepAndCount(): void
    {
        $out = $this->runBin('bin/jit.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testVmStrictTypesTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: count(): Argument #2 (\$mode) must be of type int, null given\n"
            ."TypeError: sizeof(): Argument #2 (\$mode) must be of type int, null given\n",
            $out
        );
    }

    public function testJitStrictTypesTypeError(): void
    {
        $out = $this->runBin('bin/jit.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: count(): Argument #2 (\$mode) must be of type int, null given\n"
            ."TypeError: sizeof(): Argument #2 (\$mode) must be of type int, null given\n",
            $out
        );
    }

    private function expectedSoftOutput(): string
    {
        // echo 'count=', count(...) — DEP prints mid-expression before the return value.
        return "count=ERR[8192]: count(): Passing null to parameter #2 (\$mode) of type int is deprecated\n"
            ."2\n"
            ."sizeof=ERR[8192]: sizeof(): Passing null to parameter #2 (\$mode) of type int is deprecated\n"
            ."2\n";
    }

    private function softProbeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
try {
    echo 'count=', count([1, 2], null), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo 'sizeof=', sizeof([1, 2], null), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
declare(strict_types=1);
error_reporting(E_ALL);
foreach (['count', 'sizeof'] as $fn) {
    try {
        $fn([1, 2], null);
        echo "ok\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31463_');
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
