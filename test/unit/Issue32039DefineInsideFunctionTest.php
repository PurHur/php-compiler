<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * define('LIT') inside a function must not re-emit TYPE_DECLARE_GLOBAL_CONST (#32039).
 *
 * php-src: Zend/zend_builtin_functions.c / Zend/zend_constants.c
 */
final class Issue32039DefineInsideFunctionTest extends TestCase
{
    public function testFunctionBodyEmitsSingleDeclareGlobalConst(): void
    {
        $path = __DIR__.'/../repro/maintainer_gap_define_inside_function.php';
        $code = file_get_contents($path);
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_define_inside_function.php');
        $this->assertSame(1, $this->countDeclareGlobalConst($block));
    }

    public function testFirstCallIsSilent(): void
    {
        $path = __DIR__.'/../repro/maintainer_gap_define_inside_function.php';
        [$stdout, $stderr] = $this->runVmScript($path, 0);
        $this->assertSame(
            "defined_ci=false\ndefined_CS=true\n",
            $stdout
        );
        $this->assertStringNotContainsString('already defined', $stderr);
    }

    public function testSecondCallWarnsAlreadyDefined(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_define_inside_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, <<<'PHP'
<?php
error_reporting(E_ALL);
function probe_define(): void
{
    define('PROBE_C_ISOLATED', 1);
}
probe_define();
probe_define();
echo 'ok', "\n";
PHP
        );
        [$stdout, $stderr] = $this->runVmScript($tmp, 0);
        @unlink($tmp);
        $this->assertSame("ok\n", $stdout);
        $this->assertMatchesRegularExpression(
            '/Constant PROBE_C_ISOLATED already defined in .+ on line \d+/',
            $stderr
        );
        $this->assertSame(1, substr_count($stderr, 'already defined'));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function runVmScript(string $scriptPath, int $expectedExit): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $proc = proc_open(
            [PHP_BINARY, $repoRoot.'/bin/vm.php', $scriptPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repoRoot
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame($expectedExit, proc_close($proc), trim($stderr."\n".$stdout));

        return [(string) $stdout, (string) $stderr];
    }

    private function countDeclareGlobalConst(Block $block): int
    {
        $seen = new \SplObjectStorage();
        $count = 0;
        $walk = static function (Block $b) use (&$walk, &$count, $seen): void {
            if ($seen->contains($b)) {
                return;
            }
            $seen[$b] = true;
            foreach ($b->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_GLOBAL_CONST === $op->type) {
                    ++$count;
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $child) {
                    if ($child instanceof Block) {
                        $walk($child);
                    }
                }
            }
            foreach ($b->blocks as $child) {
                if ($child instanceof Block) {
                    $walk($child);
                }
            }
        };
        $walk($block);

        return $count;
    }
}
