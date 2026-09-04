<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Zero-width preg_replace must retain the bumped subject unit (#36380).
 *
 * php-src: ext/pcre/php_pcre.c php_pcre_replace_impl — empty-match advance.
 */
final class PregReplaceZerowidth36380Test extends TestCase
{
    private const CODE = <<<'PHP'
echo preg_replace('/^[ ]{0,2}/', '', 'the rest of it'), "\n";
echo preg_replace('/^[ ]{0,2}+/', '', 'the rest of it'), "\n";
echo preg_replace('/a*/', '', 'bbb'), "\n";
echo preg_replace('/a*/', 'X', 'bbb'), "\n";
echo preg_replace('/^/', 'Y', 'ab'), "\n";
echo preg_replace('/(?=b)/', 'X', 'ab'), "\n";
echo preg_replace('/b*/', '', 'abc'), "\n";
PHP;

    private const EXPECT = "the rest of it\nthe rest of it\nbbb\nXbXbXbX\nYab\naXb\nac\n";

    public function testVmMatchesZend(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php'));
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testAotNativeBinaryMatchesZend(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame(self::EXPECT, $this->runAotBinary());
    }

    private function runAotBinary(): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_preg_zw_');
        $out = $tmp . '_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n" . self::CODE);
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

        return (string) $result;
    }

    private function runBin(string $relBin): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_preg_zw_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n" . self::CODE);
        $env = $_ENV;
        $run = proc_open(
            ['php', $repo . '/' . $relBin, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($run);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($run), trim((string) $err));
        @unlink($tmp);

        return (string) $out;
    }
}
