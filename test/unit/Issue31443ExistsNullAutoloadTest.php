<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * class/interface/trait/enum_exists(..., null) — soft-null DEP + bool result (#31443).
 *
 * php-src: Zend/zend_builtin_functions.c — Z_PARAM_BOOL $autoload
 */
final class Issue31443ExistsNullAutoloadTest extends TestCase
{
    public function testVmSoftNullDepAndResults(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode());
        $this->assertSame($this->expectedDepOutput(), $out);
    }

    public function testJitSoftNullDepAndResults(): void
    {
        $out = $this->runBin('bin/jit.php', $this->probeCode());
        $this->assertSame($this->expectedDepOutput(), $out);
    }

    public function testVmStrictTypesTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: class_exists(): Argument #2 (\$autoload) must be of type bool, null given\n",
            $out
        );
    }

    public function testJitStrictTypesTypeError(): void
    {
        $out = $this->runBin('bin/jit.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: class_exists(): Argument #2 (\$autoload) must be of type bool, null given\n",
            $out
        );
    }

    private function expectedDepOutput(): string
    {
        $lines = [];
        foreach ([
            ['class_exists', 'true'],
            ['interface_exists', 'true'],
            ['trait_exists', 'false'],
            ['enum_exists', 'false'],
        ] as [$fn, $result]) {
            $lines[] = "DEPRECATED: {$fn}(): Passing null to parameter #2 (\$autoload) of type bool is deprecated";
            $lines[] = $result;
        }

        return implode("\n", $lines)."\n";
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
var_export(class_exists('stdClass', null));
echo "\n";
var_export(interface_exists('Traversable', null));
echo "\n";
var_export(trait_exists('NoSuchTrait', null));
echo "\n";
var_export(enum_exists('NoSuchEnum', null));
echo "\n";
PHP;
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
declare(strict_types=1);
error_reporting(E_ALL);
try {
    var_export(class_exists('stdClass', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31443_');
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
