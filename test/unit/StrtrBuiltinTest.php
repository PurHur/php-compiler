<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * strtr() VM/AOT smoke (issue #1030).
 */
final class StrtrBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
echo strtr('abc', 'a', 'A'), "\n";
echo strtr('baab', 'ab', '12'), "\n";
echo strtr('hello', 'lo', '12'), "\n";
echo strtr('same', '', 'x'), "\n";
echo strtr('baab', ['a' => 'o']), "\n";
PHP;

    private const EXPECT = "Abc\n2112\nhe112\nsame\nboob\n";

    private const SCALAR_CODE = <<<'PHP'
echo strtr(123, '1', '9'), "\n";
echo strtr(true, ['1' => '9']), "\n";
PHP;

    private const SCALAR_EXPECT = "923\n9\n";

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php'));
    }

    public function testVmScalarCoercionMatchesZend(): void
    {
        $this->assertSame(self::SCALAR_EXPECT, $this->runBin('bin/vm.php', self::SCALAR_CODE));
    }

    public function testVmEmptyReplacementKeyWarns(): void
    {
        $code = <<<'PHP'
error_reporting(E_ALL);
$warns = [];
set_error_handler(static function (int $no, string $msg) use (&$warns): bool {
    $warns[] = $no . ':' . $msg;
    return true;
});
$out = strtr('ab', ['' => 'x', 'a' => 'A']);
echo 'out=' . $out . "\n";
echo 'warns=' . json_encode($warns) . "\n";
PHP;
        $expect = "out=Ab\nwarns=[\"2:strtr(): Ignoring replacement of empty string\"]\n";
        $this->assertSame($expect, $this->runBin('bin/vm.php', $code));
    }

    /** Nested array replace values — convert_to_string / lazy zval_get_tmp_string (#28978). */
    public function testVmNestedArrayReplaceValueMatchesZend(): void
    {
        $code = (string) file_get_contents(
            dirname(__DIR__, 2).'/test/repro/issue_28978_strtr_nested_array_value.php'
        );
        $expect = "Hi\nunused_warns=[]\nArrayi\nused_warns=[\"2:Array to string conversion\"]\n"
            ."Error:Object of class stdClass could not be converted to string\n";
        $this->assertSame($expect, $this->runBin('bin/vm.php', $code));
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testJitEmptyReplacementKeyWarns(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $expect = "out=Ab\nwarns=[\"2:strtr(): Ignoring replacement of empty string\"]\nat_type=2\nat_msg=yes\n";
        $this->assertSame(
            $expect,
            $this->runBin('bin/jit.php', file_get_contents(
                dirname(__DIR__, 2) . '/test/repro/issue_26704_strtr_empty_replacement_key.php'
            ) ?: '')
        );
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
        // Two-string + array-pairs forms (#27056 NestedJIT-safe StrtrArrayJitHelper).
        $code = <<<'PHP'
echo strtr('abc', 'a', 'A'), "\n";
echo strtr('baab', 'ab', '12'), "\n";
echo strtr('hello', 'lo', '12'), "\n";
echo strtr('same', '', 'x'), "\n";
echo strtr('hi', ['h' => 'H', 'i' => 'I']), "\n";
echo strtr('baab', ['a' => 'o']), "\n";
PHP;
        $expect = "Abc\n2112\nhe112\nsame\nHI\nboob\n";
        $this->assertSame($expect, $this->runAotBinary($code));
    }

    private function runAotBinary(string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_strtr_');
        $out = $tmp . '_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n" . $code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $compile = proc_open(
            ['php', $repo . '/bin/compile.php', '-o', $out, $tmp],
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

        return $this->normalize((string) $result);
    }

    private function runBin(string $bin, string $code = self::CODE): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo . '/' . $bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_strtr_');
        $this->assertNotFalse($tmp);
        // Repro files already include <?php
        $body = str_starts_with(ltrim($code), '<?php') ? $code : ("<?php\n" . $code);
        file_put_contents($tmp, $body);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(['php', $path, $tmp], $descriptor, $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $result = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc));
        @unlink($tmp);

        return $this->normalize((string) $result);
    }

    private function normalize(string $output): string
    {
        return str_replace("\r\n", "\n", $output);
    }
}
