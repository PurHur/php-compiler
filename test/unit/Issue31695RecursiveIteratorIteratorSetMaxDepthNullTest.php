<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * RecursiveIteratorIterator::setMaxDepth(null) — soft-null E_DEPRECATED then maxDepth=0 (#31695).
 *
 * php-src: ext/spl/spl_iterators.c — zim_RecursiveIteratorIterator_setMaxDepth
 */
final class Issue31695RecursiveIteratorIteratorSetMaxDepthNullTest extends TestCase
{
    public function testVmSetMaxDepthNullDeprecationThenZero(): void
    {
        $out = $this->runBin('bin/vm.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testJitSetMaxDepthNullDeprecationThenZero(): void
    {
        $out = $this->runBin('bin/jit.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testVmStrictTypesSetMaxDepthNullTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: RecursiveIteratorIterator::setMaxDepth(): Argument #1 (\$maxDepth) must be of type int, null given\n",
            $out
        );
    }

    public function testJitStrictTypesSetMaxDepthNullTypeError(): void
    {
        $out = $this->runBin('bin/jit.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: RecursiveIteratorIterator::setMaxDepth(): Argument #1 (\$maxDepth) must be of type int, null given\n",
            $out
        );
    }

    private function expectedSoftOutput(): string
    {
        return "DEP:RecursiveIteratorIterator::setMaxDepth(): Passing null to parameter #1 (\$maxDepth) of type int is deprecated\n"
            ."max=0\n";
    }

    private function softProbeCode(): string
    {
        return <<<'PHP'
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP:{$msg}\n";
        return true;
    }
    echo "E{$no}:{$msg}\n";
    return true;
});
$r = new RecursiveIteratorIterator(new RecursiveArrayIterator([1]));
try {
    $r->setMaxDepth(null);
    echo 'max=';
    var_export($r->getMaxDepth());
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
PHP;
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
error_reporting(E_ALL);
$r = new RecursiveIteratorIterator(new RecursiveArrayIterator([1]));
try {
    $r->setMaxDepth(null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31695_');
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
