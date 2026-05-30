<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** unpack() VM and AOT smoke (issue #3188). */
final class UnpackBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$r = unpack('N', pack('N', 42));
echo $r[1], "\n";
$r = unpack('n', pack('n', 0x1234));
echo $r[1], "\n";
$r = unpack('H4', pack('H4', 'dead'));
echo $r[1], "\n";
PHP;

    private const EXPECT = "42\n4660\ndead\n";

    private const INSUFFICIENT_CODE = <<<'PHP'
function unpack_warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('unpack_warn_capture');
$r = unpack('N', 'abcd', 1);
echo $r === false ? 'false' : 'bad', "\n";
$r = unpack('N', pack('N', 42));
echo $r[1], "\n";
PHP;

    private const INSUFFICIENT_EXPECT = <<<'EXPECT'
W:unpack(): Type N: not enough input, need 4, have 3
false
42

EXPECT;

    private const INSUFFICIENT_AOT_CODE = <<<'PHP'
unpack('N', 'abcd', 1);
$r = unpack('N', pack('N', 42));
echo $r[1], "\n";
PHP;

    private const INSUFFICIENT_AOT_STDOUT = <<<'EXPECT'
42

EXPECT;

    public function testVmUnpack(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php', self::CODE));
    }

    public function testVmUnpackInsufficientData(): void
    {
        $this->assertSame(self::INSUFFICIENT_EXPECT, $this->runBin('bin/vm.php', self::INSUFFICIENT_CODE));
    }

    /**
     * @group llvm
     */
    public function testAotNativeBinaryUnpackInsufficientData(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        [$stdout, $stderr, $exit] = $this->runAotBinaryWithStderr(self::INSUFFICIENT_AOT_CODE);
        $this->assertSame(0, $exit, $stderr ?: 'AOT run failed');
        $this->assertSame(self::INSUFFICIENT_AOT_STDOUT, $stdout);
        $this->assertStringContainsString(
            'unpack(): Type N: not enough input, need 4, have 3',
            $stderr
        );
    }

    /**
     * @group llvm
     */
    public function testAotNativeBinaryUnpack(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame(self::EXPECT, $this->runAotBinary(self::CODE));
    }

    private function runAotBinary(string $code): string
    {
        [$stdout, , $exit] = $this->runAotBinaryWithStderr($code);
        $this->assertSame(0, $exit, 'AOT run failed');

        return $stdout;
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function runAotBinaryWithStderr(string $code): array
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_unpack_');
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
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_unpack_vm_');
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

        return $stdout;
    }
}
