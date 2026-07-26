<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * VM: $obj=null and overwrite assignment run __destruct before later output (#23484).
 *
 * @see Zend/zend_objects.c zend_objects_destroy_object
 */
final class DestructAssignNullVmTest extends TestCase
{
    public function testNullAssignRunsDestructorBeforeFollowingEcho(): void
    {
        $path = dirname(__DIR__).'/repro/destruct_assign_null_23484.php';
        $this->assertSame("12[D:A]34\n[D:B]", $this->runVm($path));
    }

    public function testOverwriteAssignRunsDestructorBeforeFollowingEcho(): void
    {
        $code = <<<'PHP'
<?php
class R {
    public function __construct(private string $n) {}
    public function __destruct() { echo "[D:{$this->n}]"; }
}
$o = new R('A');
$o = new R('B');
echo 'X';
echo "\n";
PHP;
        $path = sys_get_temp_dir().'/destruct_overwrite_23484_'.getmypid().'.php';
        file_put_contents($path, $code);
        try {
            $this->assertSame("[D:A]X\n[D:B]", $this->runVm($path));
        } finally {
            @unlink($path);
        }
    }

    public function testEchoLineNumberDoesNotKeepAssignResultAlive(): void
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile(
            <<<'PHP'
<?php
class C {
    public function __construct(private string $n) {}
    public function __destruct() { echo "[D:{$this->n}]"; }
}
echo '1';
$a = new C('A');
echo '2';
$a = null;
echo '3';
PHP,
            'destruct_liveness_23484.php'
        );
        $assignResultAlive = false;
        for ($i = 0; $i < $block->nOpCodes; ++$i) {
            $op = $block->opCodes[$i];
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            $result = (int) $op->arg1;
            if ($block->isNamedVariableSlot($result)) {
                continue;
            }
            if (!$block->assignTempSlotIsDead($result)) {
                $assignResultAlive = true;
                break;
            }
        }
        $this->assertFalse(
            $assignResultAlive,
            'ECHO startLine must not keep assign-result object temps live (#23484)'
        );
    }

    private function runVm(string $scriptPath): string
    {
        $repo = dirname(__DIR__, 2);
        $proc = proc_open(
            ['php', $repo.'/bin/vm.php', $scriptPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim((string) $err));

        return (string) $out;
    }
}
