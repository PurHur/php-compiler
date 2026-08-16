<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ArrayObject/ArrayIterator::__construct(null $flags) — soft-null E_DEPRECATED (#31648).
 *
 * php-src: ext/spl/spl_array.c — zim_ArrayObject___construct / zim_ArrayIterator___construct
 */
final class Issue31648ArrayObjectArrayIteratorNullFlagsTest extends TestCase
{
    public function testVmNullFlagsDeprecation(): void
    {
        $out = $this->runBin('bin/vm.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testJitNullFlagsDeprecation(): void
    {
        $out = $this->runBin('bin/jit.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testVmStrictTypesNullFlagsTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: ArrayObject::__construct(): Argument #2 (\$flags) must be of type int, null given\n",
            $out
        );
    }

    private function expectedSoftOutput(): string
    {
        return "DEP:ArrayObject::__construct(): Passing null to parameter #2 (\$flags) of type int is deprecated\n"
            ."ArrayObject flags=0\n"
            ."DEP:ArrayIterator::__construct(): Passing null to parameter #2 (\$flags) of type int is deprecated\n"
            ."ArrayIterator flags=0\n";
    }

    private function softProbeCode(): string
    {
        return <<<'PHP'
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    if ($errno === E_DEPRECATED) {
        echo 'DEP:', $errstr, "\n";
        return true;
    }
    return false;
});
$o = new ArrayObject([1], null);
echo 'ArrayObject flags=', $o->getFlags(), "\n";
$i = new ArrayIterator([1], null);
echo 'ArrayIterator flags=', $i->getFlags(), "\n";
PHP;
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
    new ArrayObject([1], null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31648_');
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
