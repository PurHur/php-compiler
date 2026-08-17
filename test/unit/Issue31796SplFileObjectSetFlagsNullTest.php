<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplFileObject::setFlags(null) — soft-null E_DEPRECATED then flags=0 (#31796).
 *
 * php-src: ext/spl/spl_directory.c — zim_SplFileObject_setFlags
 */
final class Issue31796SplFileObjectSetFlagsNullTest extends TestCase
{
    public function testVmSetFlagsNullDeprecationThenZero(): void
    {
        $out = $this->runBin('bin/vm.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testJitSetFlagsNullDeprecationThenZero(): void
    {
        $out = $this->runBin('bin/jit.php', $this->softProbeCode());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testVmStrictTypesSetFlagsNullTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbeCode());
        $this->assertSame(
            "TypeError: SplFileObject::setFlags(): Argument #1 (\$flags) must be of type int, null given\n",
            $out
        );
    }

    private function expectedSoftOutput(): string
    {
        return "DEP:SplFileObject::setFlags(): Passing null to parameter #1 (\$flags) of type int is deprecated\n"
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
$tmp = tempnam(sys_get_temp_dir(), 'sfo31796');
file_put_contents($tmp, "a\nb\n");
try {
    $f = new SplFileObject($tmp);
    $f->setFlags(null);
    echo 'flags=' . $f->getFlags() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
@unlink($tmp);
PHP;
    }

    private function strictProbeCode(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
error_reporting(E_ALL);
$tmp = tempnam(sys_get_temp_dir(), 'sfo31796s');
file_put_contents($tmp, "a\n");
try {
    $f = new SplFileObject($tmp);
    $f->setFlags(null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
@unlink($tmp);
PHP;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_31796_');
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
