<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** use function / use const imports (issue #2325). */
final class UseFunctionImportTest extends TestCase
{
    private const CODE = <<<'PHP'
namespace N {
    const ANSWER = 42;
    function greet(): string {
        return 'hi';
    }
}
namespace User {
    use const N\ANSWER;
    use function N\greet;
    echo greet(), ' ', ANSWER;
}
PHP;

    private const EXPECT = 'hi 42';

    public function testVmUseFunctionAndUseConst(): void
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile("<?php\n".self::CODE, 'use_import.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(self::EXPECT, ob_get_clean());
    }

    /**
     * @group llvm
     */
    public function testAotUseFunctionAndUseConst(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame(self::EXPECT, $this->runAotBinary());
    }

    private function runAotBinary(): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_use_import_');
        $out = $tmp.'_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE);
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
        $this->assertSame(0, proc_close($compile), trim((string) $compileErr));
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
        $this->assertSame(0, proc_close($run), trim((string) $runErr));
        @unlink($tmp);
        @unlink($out);

        return $stdout !== false ? rtrim($stdout) : '';
    }
}
