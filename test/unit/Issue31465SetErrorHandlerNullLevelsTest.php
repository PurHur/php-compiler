<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * set_error_handler(..., null) — soft-null DEP for $error_levels (#31465).
 *
 * php-src: Zend/zend_builtin_functions.c PHP_FUNCTION(set_error_handler)
 */
final class Issue31465SetErrorHandlerNullLevelsTest extends TestCase
{
    public function testVmSoftNullDepAndInstall(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testJitSoftNullDepAndInstall(): void
    {
        $out = $this->runBin('bin/jit.php', $this->jitProbeCode());
        $this->assertSame($this->expectedJitSoftOutput(), $out);
    }

    public function testVmStrictTypesTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: set_error_handler(): Argument #2 (\$error_levels) must be of type int, null given\n",
            $out
        );
    }

    public function testJitStrictTypesTypeError(): void
    {
        $out = $this->runBin('bin/jit.php', $this->strictJitProbeCode());
        $this->assertSame(
            "TypeError: set_error_handler(): Argument #2 (\$error_levels) must be of type int, null given\n",
            $out
        );
    }

    private function expectedSoftOutput(): string
    {
        return "prev_callable=true\n"
            ."ERR[8192]: set_error_handler(): Passing null to parameter #2 (\$error_levels) of type int is deprecated\n";
    }

    private function expectedJitSoftOutput(): string
    {
        return "ERR[8192]: set_error_handler(): Passing null to parameter #2 (\$error_levels) of type int is deprecated\n"
            ."prev_callable=true\n";
    }

    private function probeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $errno, string $errstr) use (&$seen): bool {
    $seen[] = [$errno, $errstr];

    return true;
});
try {
    $prev = set_error_handler(static function (): bool {
        return false;
    }, null);
    echo 'prev_callable=', var_export(is_callable($prev), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
foreach ($seen as $row) {
    echo 'ERR[', $row[0], ']: ', $row[1], "\n";
}
PHP;
    }

    private function jitProbeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);

function issue31465_seh_capture(int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
}

function issue31465_seh_next(): bool {
    return false;
}

set_error_handler('issue31465_seh_capture');
try {
    $prev = set_error_handler('issue31465_seh_next', null);
    echo 'prev_callable=', var_export(is_callable($prev), true), "\n";
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
try {
    set_error_handler(static function (): bool {
        return false;
    }, null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
    }

    private function strictJitProbeCode(): string
    {
        return <<<'PHP'
declare(strict_types=1);
error_reporting(E_ALL);

function issue31465_seh_strict_cb(): bool {
    return false;
}

try {
    set_error_handler('issue31465_seh_strict_cb', null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31465_');
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
