<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * RecursiveIteratorIterator null $mode — soft-null E_DEPRECATED then LEAVES_ONLY (#31622).
 *
 * php-src: ext/spl/spl_iterators.c — zim_RecursiveIteratorIterator___construct
 */
final class Issue31622RecursiveIteratorIteratorNullModeTest extends TestCase
{
    public function testVmNullModeDeprecationThenLeavesOnly(): void
    {
        $out = $this->runBin('bin/vm.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testJitNullModeDeprecationThenLeavesOnly(): void
    {
        $out = $this->runBin('bin/jit.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testVmStrictTypesNullModeTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: RecursiveIteratorIterator::__construct(): Argument #2 (\$mode) must be of type int, null given\n",
            $out
        );
    }

    private function expectedSoftOutput(): string
    {
        return "DEP:RecursiveIteratorIterator::__construct(): Passing null to parameter #2 (\$mode) of type int is deprecated\n"
            ."[2]\n";
    }

    private function softProbeCode(): string
    {
        return <<<'PHP'
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(static function (int $errno, string $errstr): bool {
    if ($errno === E_DEPRECATED) {
        echo 'DEP:', $errstr, "\n";
        return true;
    }
    return false;
});
$r = new RecursiveIteratorIterator(new RecursiveArrayIterator([1, [2]]), null);
echo json_encode(iterator_to_array($r)), "\n";
PHP;
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
    new RecursiveIteratorIterator(new RecursiveArrayIterator([1, [2]]), null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31622_');
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
