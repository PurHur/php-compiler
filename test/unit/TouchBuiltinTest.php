<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * touch() VM/AOT smoke.
 */
final class TouchBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$base = 'test/compliance/cases/stdlib/touch_fixture';
$path = $base . '/marker.txt';
@unlink($path);
if (touch($path)) {
    echo 'create', "\n";
} else {
    echo 'nocreate', "\n";
}
$t = 1000000000;
if (touch($path, $t)) {
    echo 'set', "\n";
} else {
    echo 'noset', "\n";
}
$m = filemtime($path);
if ($m === $t) {
    echo 'mtime', "\n";
} else {
    echo 'badmtime', "\n";
}
// Separate path — avoid prior filemtime positive cache (#25853); no clearstatcache needed.
$path2 = $base . '/marker2.txt';
@unlink($path2);
$mtime = 1000000100;
$atime = 1000000200;
if (touch($path2, $mtime, $atime)) {
    echo 'atime_set', "\n";
} else {
    echo 'noatime', "\n";
}
$s = stat($path2);
if ($s['mtime'] === $mtime && $s['atime'] === $atime) {
    echo 'atime_ok', "\n";
} else {
    echo 'badatime', "\n";
}
@unlink($path);
@unlink($path2);
PHP;

    private const CODE_VM = <<<'PHP'
$base = 'test/compliance/cases/stdlib/touch_fixture';
$path = $base . '/marker.txt';
@unlink($path);
if (touch($path)) {
    echo 'create', "\n";
} else {
    echo 'nocreate', "\n";
}
$t = 1000000000;
if (touch($path, $t)) {
    echo 'set', "\n";
} else {
    echo 'noset', "\n";
}
$m = filemtime($path);
if ($m === $t) {
    echo 'mtime', "\n";
} else {
    echo 'badmtime', "\n";
}
$path2 = $base . '/marker2.txt';
@unlink($path2);
$mtime = 1000000100;
$atime = 1000000200;
if (touch($path2, $mtime, $atime)) {
    echo 'atime_set', "\n";
} else {
    echo 'noatime', "\n";
}
$s = stat($path2);
if ($s['mtime'] === $mtime && $s['atime'] === $atime) {
    echo 'atime_ok', "\n";
} else {
    echo 'badatime', "\n";
}
if (touch('/no/such/phpc-touch-path')) {
    echo 'bad', "\n";
} else {
    echo 'gone', "\n";
}
@unlink($path);
@unlink($path2);
PHP;

    private const EXPECT = "create\nset\nmtime\natime_set\natime_ok\n";

    private const EXPECT_VM = "create\nset\nmtime\natime_set\natime_ok\ngone\n";

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2).'/test/compliance/cases/stdlib/touch_fixture';
        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }
        $path = $base.'/marker.txt';
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::EXPECT_VM, $this->runBin('bin/vm.php', self::CODE_VM));
    }

    public function testVmNamedMtimeAtimeParams(): void
    {
        $code = <<<'PHP'
$f = sys_get_temp_dir() . '/phpc_touch_named_' . getmypid();
$mtime = 1000000100;
$atime = 1000000200;
touch($f, mtime: $mtime, atime: $atime);
$s = stat($f);
var_export($s['mtime'] === $mtime && $s['atime'] === $atime);
@unlink($f);
PHP;
        $this->assertSame('true', trim($this->runBin('bin/vm.php', $code)));
    }

    /** Issue #28995 — timed touch without a prior stat must expose mtime/atime immediately. */
    public function testVmTimedTouchVisibleWithoutPriorStat(): void
    {
        $code = <<<'PHP'
$p = tempnam(sys_get_temp_dir(), 'phpc_touch_noprior_');
$mtime = 1600000000;
$atime = 1599999900;
touch($p, $mtime, $atime);
echo filemtime($p) === $mtime && fileatime($p) === $atime ? 'ok3' : 'bad3';
echo "\n";
@unlink($p);
$p = tempnam(sys_get_temp_dir(), 'phpc_touch_noprior2_');
touch($p, $mtime);
echo filemtime($p) === $mtime && fileatime($p) === $mtime ? 'ok2' : 'bad2';
echo "\n";
@unlink($p);
PHP;
        $this->assertSame("ok3\nok2\n", $this->runBin('bin/vm.php', $code));
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testAotNativeBinaryMatchesPhpSubset(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame(self::EXPECT, $this->runAotBinary());
    }

    private function runAotBinary(): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_touch_');
        $out = $tmp.'_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $compile = proc_open(
            ['php', $repo.'/bin/compile.php', '-o', $out, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($compile), trim((string) $compileErr));
        $run = proc_open(
            [$out],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $runPipes,
            $repo,
            $env
        );
        $this->assertIsResource($run);
        fclose($runPipes[0]);
        $result = stream_get_contents($runPipes[1]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $this->assertSame(0, proc_close($run));
        @unlink($tmp);
        @unlink($out);

        return (string) $result;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo.'/'.$bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_touch_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(['php', $path, $tmp], $descriptor, $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, trim((string) $err));

        return (string) $out;
    }
}
