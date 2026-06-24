<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ini_set() / ini_get() VM and AOT smoke (issue #1374, #1492). */
final class IniSetBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$old = ini_set('error_reporting', '0');
echo is_string($old) ? "er-ok\n" : "er-fail\n";
echo ini_set('unknown_ini_key', 'x') === false ? "unknown-false\n" : "unknown-bad\n";
$unlimited = '-'.'1';
echo ini_set('memory_limit', $unlimited) === false ? "ml-fail\n" : "ml-ok\n";
echo ini_get('memory_limit') === $unlimited ? "ml-get\n" : "ml-get-fail\n";
ini_set('display_errors', '1');
echo ini_get('display_errors') === '1' ? "get-ok\n" : "get-fail\n";
echo ini_get('unknown_ini_key') === false ? "get-false\n" : "get-bad\n";
$key = 'error_reporting';
ini_set($key, '0');
echo ini_get($key) === '0' ? "var-key-ok\n" : "var-key-fail\n";
PHP;

    private const EXPECT = "er-ok\nunknown-false\nml-ok\nml-get\nget-ok\nget-false\nvar-key-ok\n";

    public function testVmIniSetSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php', self::CODE));
    }

    public function testVmIniIntrospectionSubset(): void
    {
        $code = <<<'PHP'
$orig = ini_get('display_errors');
ini_set('display_errors', '0');
ini_restore('display_errors');
echo ini_get('display_errors') === $orig ? "restore_ok\n" : "restore_fail\n";
$all = ini_get_all();
echo isset($all['display_errors']) ? "all_ok\n" : "all_fail\n";
echo get_cfg_var('display_errors') === '' ? "cfg_ok\n" : "cfg_fail\n";
PHP;
        $this->assertSame("restore_ok\nall_ok\ncfg_ok\n", $this->runBin('bin/vm.php', $code));
    }

    public function testVmIniAlterAlias(): void
    {
        $code = <<<'PHP'
if (!function_exists('ini_alter')) {
    echo "missing\n";
    exit(1);
}
$old = ini_alter('error_reporting', '0');
echo is_string($old) ? "alter-ok\n" : "alter-fail\n";
echo ini_alter('unknown_ini_key', 'x') === false ? "unknown-false\n" : "unknown-bad\n";
PHP;
        $this->assertSame("alter-ok\nunknown-false\n", $this->runBin('bin/vm.php', $code));
    }

    /** @group llvm */
    public function testAotNativeBinaryIniGetAll(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $code = <<<'PHP'
$all = ini_get_all();
echo isset($all['display_errors']) ? "all_ok\n" : "all_fail\n";
$flat = ini_get_all(null, false);
echo is_string($flat['display_errors']) ? "flat_ok\n" : "flat_fail\n";
echo ini_get_all('nonexistent') === false ? "ext_false\n" : "ext_bad\n";
PHP;
        $this->assertSame("all_ok\nflat_ok\next_false\n", $this->runAotBinary($code));
    }

    /** @group llvm */
    public function testAotNativeBinaryIniSetSubset(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame(self::EXPECT, $this->runAotBinary(self::CODE));
    }

    private function runAotBinary(string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_ini_set_');
        $out = $tmp.'_bin';
        file_put_contents($tmp, "<?php\n".$code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $compile = proc_open(['php', $repo.'/bin/compile.php', '-o', $out, $tmp], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $repo, $env);
        fclose($pipes[0]); fclose($pipes[1]); $ce = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($compile), $ce ?: 'compile failed');
        $run = proc_open([$out], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $repo, $env);
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]); $re = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($run), $re ?: 'run failed');
        @unlink($tmp); @unlink($out);
        return $stdout;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_ini_set_vm_');
        file_put_contents($tmp, "<?php\n".$code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(['php', $repo.'/'.$bin, $tmp], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $repo, $env);
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), $stderr ?: 'VM run failed');
        @unlink($tmp);
        return $stdout;
    }
}
