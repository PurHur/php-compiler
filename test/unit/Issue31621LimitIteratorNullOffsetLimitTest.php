<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * LimitIterator null $offset/$limit — soft-null E_DEPRECATED then OOB (#31621).
 *
 * php-src: ext/spl/spl_iterators.c — zim_LimitIterator___construct
 */
final class Issue31621LimitIteratorNullOffsetLimitTest extends TestCase
{
    public function testVmNullOffsetLimitDeprecationThenOutOfBounds(): void
    {
        $out = $this->runBin('bin/vm.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testJitNullOffsetLimitDeprecationThenOutOfBounds(): void
    {
        $out = $this->runBin('bin/jit.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testVmStrictTypesNullOffsetTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: LimitIterator::__construct(): Argument #2 (\$offset) must be of type int, null given\n",
            $out
        );
    }

    public function testJitStrictTypesNullOffsetTypeError(): void
    {
        $out = $this->runBin('bin/jit.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: LimitIterator::__construct(): Argument #2 (\$offset) must be of type int, null given\n",
            $out
        );
    }

    public function testAotNullOffsetLimitDeprecationAndEmptyWindow(): void
    {
        $repo = dirname(__DIR__, 2);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $outBin = tempnam(sys_get_temp_dir(), 'phpc_31621_aot_');
        $this->assertNotFalse($outBin);
        @unlink($outBin);
        $proc = proc_open(
            ['php', $repo.'/bin/compile.php', '-o', $outBin, $repo.'/test/repro/maintainer_gap_limititerator_null_offset_limit_aot.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $compileOut = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), $compileOut);
        $this->assertFileExists($outBin);
        $run = proc_open(
            [$outBin],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $rpipes,
            $repo,
            $env
        );
        $this->assertIsResource($run);
        fclose($rpipes[0]);
        $stdout = stream_get_contents($rpipes[1]);
        $stderr = stream_get_contents($rpipes[2]);
        fclose($rpipes[1]);
        fclose($rpipes[2]);
        $this->assertSame(0, proc_close($run), $stdout.$stderr);
        @unlink($outBin);
        // AOT HT snapshot: limit 0 → empty array (no rewind OOB); soft-null DEPs on stderr.
        $this->assertSame("ok:[]\n", $stdout);
        $this->assertStringContainsString(
            'LimitIterator::__construct(): Passing null to parameter #2 ($offset) of type int is deprecated',
            $stderr
        );
        $this->assertStringContainsString(
            'LimitIterator::__construct(): Passing null to parameter #3 ($limit) of type int is deprecated',
            $stderr
        );
    }

    private function expectedSoftOutput(): string
    {
        return "DEP:LimitIterator::__construct(): Passing null to parameter #2 (\$offset) of type int is deprecated\n"
            ."DEP:LimitIterator::__construct(): Passing null to parameter #3 (\$limit) of type int is deprecated\n"
            ."OutOfBoundsException: Cannot seek to 0 which is behind offset 0 plus count 0\n";
    }

    private function softProbeCode(): string
    {
        return file_get_contents(__DIR__.'/../repro/maintainer_gap_limititerator_null_offset_limit.php');
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
    new LimitIterator(new ArrayIterator([1, 2, 3]), null, null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31621_');
        $this->assertNotFalse($tmp);
        // softProbeCode already includes <?php; strict probe embeds it.
        if (!str_starts_with(ltrim($code), '<?php')) {
            $code = "<?php\n".$code;
        }
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
