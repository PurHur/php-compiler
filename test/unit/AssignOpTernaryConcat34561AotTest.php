<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\OpCode;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** #34561 — ternary concat-assign must not SIGSEGV under AOT. */
final class AssignOpTernaryConcat34561AotTest extends TestCase
{
    public function testVmTernaryAssignConcatMatchesZend(): void
    {
        $this->assertBackendMatchesZend('vm');
    }

    public function testAotTernaryAssignConcatMatchesZend(): void
    {
        $this->assertBackendMatchesZend('aot');
    }

    public function testPeepholeFusesConcatWithSiblingTernaryAssign(): void
    {
        $code = <<<'PHP'
<?php
$g = '';
true ? ($g .= 'A') : ($g .= 'X');
echo $g;
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'tern.php');
        $this->assertNotNull($block);
        $foundInPlace = false;
        $seen = new \SplObjectStorage();
        $q = [$block];
        while ($q) {
            $b = array_shift($q);
            if ($seen->contains($b)) {
                continue;
            }
            $seen->attach($b);
            foreach ($b->opCodes as $op) {
                if (
                    OpCode::TYPE_CONCAT === $op->type
                    && null !== $op->arg1
                    && null !== $op->arg2
                    && (int) $op->arg1 === (int) $op->arg2
                ) {
                    $foundInPlace = true;
                }
                if ($op->block1) {
                    $q[] = $op->block1;
                }
                if ($op->block2) {
                    $q[] = $op->block2;
                }
            }
        }
        $this->assertTrue($foundInPlace, 'expected in-place CONCAT after dual-assign peephole');
    }

    private function assertBackendMatchesZend(string $backend): void
    {
        $path = __DIR__ . '/../repro/issue_34561_ternary_assign_concat_aot.php';
        $zend = $this->runPhp(file_get_contents($path));
        if ('vm' === $backend) {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(file_get_contents($path), 't.php');
            ob_start();
            try {
                $runtime->run($block);
            } catch (\PHPCompiler\VM\ScriptExit $e) {
            }
            $this->assertSame($zend, ob_get_clean(), 'VM vs Zend');
            return;
        }
        $bin = sys_get_temp_dir() . '/phpc_34561_' . md5($path) . '.bin';
        $proc = proc_open(
            ['php', 'bin/compile.php', '-o', $bin, $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2)
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), "compile failed: $err");
        $aot = shell_exec(escapeshellarg($bin) . ' 2>&1');
        @unlink($bin);
        $this->assertSame($zend, (string) $aot, 'AOT vs Zend');
    }

    private function runPhp(string $code): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'z34561');
        file_put_contents($tmp, $code);
        $out = (string) shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);

        return $out;
    }
}
