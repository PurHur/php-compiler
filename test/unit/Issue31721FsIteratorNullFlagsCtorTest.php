<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * FilesystemIterator family null $flags ctor — soft-null E_DEPRECATED then flags=0 (#31721).
 *
 * php-src: ext/spl/spl_directory.c — zim_FilesystemIterator___construct / Recursive / Glob
 */
final class Issue31721FsIteratorNullFlagsCtorTest extends TestCase
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
            "TypeError: FilesystemIterator::__construct(): Argument #2 (\$flags) must be of type int, null given\n",
            $out
        );
    }

    public function testJitStrictTypesNullFlagsTypeError(): void
    {
        $out = $this->runBin('bin/jit.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: FilesystemIterator::__construct(): Argument #2 (\$flags) must be of type int, null given\n",
            $out
        );
    }

    public function testAotNullFlagsDeprecationAndConstructOk(): void
    {
        $repo = dirname(__DIR__, 2);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $outBin = tempnam(sys_get_temp_dir(), 'phpc_31721_aot_');
        $this->assertNotFalse($outBin);
        @unlink($outBin);
        $proc = proc_open(
            [
                'php',
                $repo.'/bin/compile.php',
                '-o',
                $outBin,
                $repo.'/test/repro/maintainer_gap_fs_iterator_null_flags_ctor_aot.php',
            ],
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
        $this->assertSame("fs_ok\ngi_ok\n", $stdout);
        $this->assertStringContainsString(
            'FilesystemIterator::__construct(): Passing null to parameter #2 ($flags) of type int is deprecated',
            $stderr
        );
        $this->assertStringContainsString(
            'GlobIterator::__construct(): Passing null to parameter #2 ($flags) of type int is deprecated',
            $stderr
        );
    }

    private function expectedSoftOutput(): string
    {
        return "== FilesystemIterator ==\n"
            ."DEP:FilesystemIterator::__construct(): Passing null to parameter #2 (\$flags) of type int is deprecated\n"
            ."flags=0\n"
            ."== RecursiveDirectoryIterator ==\n"
            ."DEP:RecursiveDirectoryIterator::__construct(): Passing null to parameter #2 (\$flags) of type int is deprecated\n"
            ."flags=0\n"
            ."== GlobIterator ==\n"
            ."DEP:GlobIterator::__construct(): Passing null to parameter #2 (\$flags) of type int is deprecated\n"
            ."flags=0\n";
    }

    private function softProbeCode(): string
    {
        return (string) file_get_contents(__DIR__.'/../repro/maintainer_gap_fs_iterator_null_flags_ctor.php');
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
error_reporting(E_ALL);
$tmpdir = sys_get_temp_dir() . '/phpc_fs_null_flags_strict_' . getmypid();
@mkdir($tmpdir);
file_put_contents("$tmpdir/a.txt", 'x');
try {
    new FilesystemIterator($tmpdir, null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
@system('rm -rf ' . escapeshellarg($tmpdir));
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31721_');
        $this->assertNotFalse($tmp);
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
