<?php

declare(strict_types=1);

namespace test\unit;

use PHPCompiler\Block;
use PHPCompiler\JIT\AotDebugSymbols;
use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

final class AotDebugSymbolsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(AotDebugSymbols::ENV);
        unset($_ENV[AotDebugSymbols::ENV], $_SERVER[AotDebugSymbols::ENV]);
    }

    public function testSymbolFromPathUsesBasename(): void
    {
        $this->assertSame('phpc_src_repro_php', AotDebugSymbols::symbolFromPath('/tmp/repro.php'));
        $this->assertSame('phpc_src_example_php', AotDebugSymbols::symbolFromPath('examples/000-HelloWorld/example.php'));
    }

    public function testScriptMainFunctionNameWhenEnabled(): void
    {
        AotDebugSymbols::enable();
        $block = new Block(null);
        $block->setScriptPath('/tmp/debug_probe.php');

        $this->assertSame('phpc_src_debug_probe_php', AotDebugSymbols::scriptMainFunctionName($block));
    }

    public function testScriptMainFunctionNameDisabledByDefault(): void
    {
        $block = new Block(null);
        $block->setScriptPath('/tmp/debug_probe.php');

        $this->assertNull(AotDebugSymbols::scriptMainFunctionName($block));
    }

    /**
     * @group llvm
     * @group aot-link
     */
    public function testAotBinaryContainsDebugSectionsAndSourceSymbol(): void
    {
        $repo = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($repo)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'phpc_dbg_');
        $this->assertNotFalse($tmp);
        $script = $tmp.'.php';
        $out = $tmp.'_bin';
        rename($tmp, $script);
        file_put_contents($script, "<?php\necho \"ok\";\n");

        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $env[AotDebugSymbols::ENV] = '1';

        $compile = proc_open(
            ['php', $repo.'/bin/compile.php', '--debug-symbols', '-o', $out, $script],
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
        $this->assertFileExists($out);

        $readelf = proc_open(
            ['readelf', '-S', $out],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $readelfPipes
        );
        $this->assertIsResource($readelf);
        fclose($readelfPipes[0]);
        $sections = stream_get_contents($readelfPipes[1]);
        fclose($readelfPipes[1]);
        fclose($readelfPipes[2]);
        $this->assertSame(0, proc_close($readelf));
        $this->assertStringContainsString('.debug_info', (string) $sections);

        $nm = proc_open(
            ['nm', '-g', $out],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $nmPipes
        );
        $this->assertIsResource($nm);
        fclose($nmPipes[0]);
        $symbols = stream_get_contents($nmPipes[1]);
        fclose($nmPipes[1]);
        fclose($nmPipes[2]);
        $this->assertSame(0, proc_close($nm));
        $this->assertStringContainsString('phpc_src_', (string) $symbols);

        @unlink($script);
        @unlink($out);
    }
}
