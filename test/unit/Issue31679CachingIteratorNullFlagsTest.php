<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * CachingIterator::__construct(null $flags) — soft-null E_DEPRECATED then flags=0 (#31679).
 *
 * php-src: ext/spl/spl_iterators.c — zim_CachingIterator___construct
 */
final class Issue31679CachingIteratorNullFlagsTest extends TestCase
{
    public function testVmNullFlagsDeprecationThenZero(): void
    {
        $out = $this->runBin('bin/vm.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testJitNullFlagsDeprecationThenZero(): void
    {
        $out = $this->runBin('bin/jit.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testVmStrictTypesNullFlagsTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: CachingIterator::__construct(): Argument #2 (\$flags) must be of type int, null given\n",
            $out
        );
    }

    public function testJitStrictTypesNullFlagsTypeError(): void
    {
        $out = $this->runBin('bin/jit.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: CachingIterator::__construct(): Argument #2 (\$flags) must be of type int, null given\n",
            $out
        );
    }

    public function testVmOmittedFlagsStillCallToString(): void
    {
        $out = $this->runBin('bin/vm.php', $this->omitProbeCode());
        $this->assertSame("flags=1\n", $out);
    }

    private function expectedSoftOutput(): string
    {
        return "DEP:CachingIterator::__construct(): Passing null to parameter #2 (\$flags) of type int is deprecated\n"
            ."flags=0\n";
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
try {
    $c = new CachingIterator(new ArrayIterator([1]), null);
    echo 'flags=' . $c->getFlags() . "\n";
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
try {
    new CachingIterator(new ArrayIterator([1]), null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
PHP;
    }

    private function omitProbeCode(): string
    {
        return <<<'PHP'
<?php
error_reporting(E_ALL);
$c = new CachingIterator(new ArrayIterator([1]));
echo 'flags=' . $c->getFlags() . "\n";
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31679_');
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
        $this->assertSame(0, $exit, "stdout=\n{$stdout}\nstderr=\n{$stderr}");

        return $stdout;
    }
}
