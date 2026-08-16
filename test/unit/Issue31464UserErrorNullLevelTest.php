<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * user_error(..., null) — soft-null DEP + ValueError ($error_level) (#31464).
 *
 * php-src: Zend/zend_builtin_functions.c PHP_FUNCTION(user_error) / trigger_error
 */
final class Issue31464UserErrorNullLevelTest extends TestCase
{
    public function testVmSoftNullDepThenValueError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testJitSoftNullDepThenValueError(): void
    {
        $out = $this->runBin('bin/jit.php', $this->probeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testVmStrictTypesTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: user_error(): Argument #2 (\$error_level) must be of type int, null given\n",
            $out
        );
    }

    public function testJitStrictTypesTypeError(): void
    {
        $out = $this->runBin('bin/jit.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: user_error(): Argument #2 (\$error_level) must be of type int, null given\n",
            $out
        );
    }

    private function expectedSoftOutput(): string
    {
        return "ERR[8192]: user_error(): Passing null to parameter #2 (\$error_level) of type int is deprecated\n"
            ."ValueError: user_error(): Argument #2 (\$error_level) must be one of E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, or E_USER_DEPRECATED\n"
            ."ERR[8192]: trigger_error(): Passing null to parameter #2 (\$error_level) of type int is deprecated\n"
            ."trigger ValueError: trigger_error(): Argument #2 (\$error_level) must be one of E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, or E_USER_DEPRECATED\n";
    }

    private function probeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
try {
    user_error('x', null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    trigger_error('x', null);
    echo "trigger ok\n";
} catch (Throwable $e) {
    echo 'trigger ', get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
declare(strict_types=1);
error_reporting(E_ALL);
try {
    user_error('x', null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31464_');
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
