<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * range() multibyte string bounds → Zend E_WARNING under PROFILE≥8.3 (#29203).
 */
final class RangeMultibyteWarnTest extends TestCase
{
    public function testVmMultibyteWarnsUnderProfile84(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "count=1\n"
            ."first_hex=e3\n"
            ."range(): Argument #1 (\$start) must be a single byte, subsequent bytes are ignored\n"
            ."range(): Argument #2 (\$end) must be a single byte, subsequent bytes are ignored\n",
            $out
        );
    }

    public function testVmSilentOnDefaultProfile(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '']);
        $this->assertSame("count=1\nfirst_hex=e3\n", $out);
    }

    public function testJitMultibyteWarnsUnderProfile84(): void
    {
        $out = $this->runBin('bin/jit.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "count=1\n"
            ."first_hex=e3\n"
            ."range(): Argument #1 (\$start) must be a single byte, subsequent bytes are ignored\n"
            ."range(): Argument #2 (\$end) must be a single byte, subsequent bytes are ignored\n",
            $out
        );
    }

    private function probeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
$msgs = [];
set_error_handler(function ($n, $m) use (&$msgs) {
    $msgs[] = $m;
    return true;
});
$r = range('あ', 'う');
restore_error_handler();
echo 'count=', count($r), "\n";
echo 'first_hex=', bin2hex($r[0] ?? ''), "\n";
echo implode("\n", $msgs);
if ([] !== $msgs) {
    echo "\n";
}
PHP;
    }

    /**
     * @param array<string, string> $extraEnv
     */
    private function runBin(string $bin, string $code, array $extraEnv): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_range_mb_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $env = $_ENV;
        foreach ($extraEnv as $k => $v) {
            if ('' === $v) {
                unset($env[$k]);
            } else {
                $env[$k] = $v;
            }
        }
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
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, (string) $err);

        return (string) $out;
    }
}
