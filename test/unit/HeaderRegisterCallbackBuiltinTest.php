<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** header_register_callback() VM + AOT smoke (issue #3759). */
final class HeaderRegisterCallbackBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
header_register_callback(function (): void {
    header('X-Registered: 1');
});
echo "body\n";
$found = false;
foreach (headers_list() as $line) {
    if (0 === strncasecmp($line, 'X-Registered:', 13)) {
        $found = true;
    }
}
echo $found ? "ok\n" : "missing\n";
PHP;

    private const EXPECT = <<<'EXPECT'
body
ok

EXPECT;

    private const AOT_CODE = <<<'PHP'
header_register_callback(function (): void {
    header('X-Registered: 1');
});
echo "body\n";
PHP;

    public function testVmHeaderRegisterCallback(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php', self::CODE));
    }

    public function testFunctionExists(): void
    {
        $out = $this->runBin(
            'bin/vm.php',
            'var_export(function_exists("header_register_callback"));'
        );
        $this->assertSame('true', trim($out));
    }

    /**
     * @group llvm
     */
    public function testAotNativeBinaryHeaderRegisterCallback(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        [$stdout, $stderr, $exit] = $this->runAotBinaryWithStderr(self::AOT_CODE);
        $this->assertSame(0, $exit, $stderr ?: 'AOT run failed');
        $this->assertStringContainsString('body', $stdout);
        $this->assertStringContainsString('X-Registered: 1', $stdout);
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function runAotBinaryWithStderr(string $code): array
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_hrc_');
        $out = $tmp.'_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $compile = proc_open(
            [PHP_BINARY, $repo.'/bin/compile.php', '-o', $out, $tmp],
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
        $this->assertSame(0, proc_close($compile), $compileErr ?: 'compile failed');
        $run = proc_open(
            [$out],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($run);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $runErr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($run);
        @unlink($tmp);
        @unlink($out);

        return [
            false !== $stdout ? $stdout : '',
            false !== $runErr ? $runErr : '',
            $exit,
        ];
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_hrc_vm_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            [PHP_BINARY, $repo.'/'.$bin, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), $stderr ?: 'VM run failed');
        @unlink($tmp);

        return false !== $stdout ? $stdout : '';
    }
}
