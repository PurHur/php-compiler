<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * class_alias(..., null) $autoload — soft-null DEP + true; strict TypeError (#31489).
 *
 * php-src: Zend/zend_builtin_functions.c — Z_PARAM_BOOL $autoload
 */
final class Issue31489ClassAliasNullAutoloadTest extends TestCase
{
    public function testVmSoftNullDepAndAlias(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testJitSoftNullDepAndAlias(): void
    {
        $out = $this->runBin('bin/jit.php', $this->probeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testVmStrictTypesTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: class_alias(): Argument #3 (\$autoload) must be of type bool, null given\n",
            $out
        );
    }

    public function testJitStrictTypesTypeError(): void
    {
        $out = $this->runBin('bin/jit.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: class_alias(): Argument #3 (\$autoload) must be of type bool, null given\n",
            $out
        );
    }

    private function expectedSoftOutput(): string
    {
        return "DEPRECATED: class_alias(): Passing null to parameter #3 (\$autoload) of type bool is deprecated\n"
            ."true\n"
            ."true\n";
    }

    private function probeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    $label = match ($errno) {
        E_DEPRECATED => 'DEPRECATED',
        E_WARNING => 'WARNING',
        default => (string) $errno,
    };
    echo $label, ': ', $errstr, "\n";

    return true;
});

class Issue31489ClassAliasProbe {}

try {
    var_export(class_alias(Issue31489ClassAliasProbe::class, 'Issue31489ClassAliasProbeAlias', null));
    echo "\n";
    var_export(class_exists('Issue31489ClassAliasProbeAlias', false));
    echo "\n";
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
class Issue31489ClassAliasStrictProbe {}
try {
    var_export(class_alias(Issue31489ClassAliasStrictProbe::class, 'Issue31489ClassAliasStrictAlias', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31489_');
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
