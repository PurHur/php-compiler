<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** foreach by-reference over object properties (#4431, #3661). */
final class ForeachObjectByRefVmTest extends TestCase
{
    public function testUserClassForeachByRefMutatesProperty(): void
    {
        $repoRoot = \dirname(__DIR__, 2);
        $vm = $repoRoot . '/bin/vm.php';
        $code = <<<'PHP'
<?php
class C { public int $a = 1; }
$o = new C();
foreach ($o as &$v) {
    $v = 2;
}
echo $o->a, "\n";
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'foreach_obj_byref_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $code);
        try {
            $proc = proc_open(
                [PHP_BINARY, $vm, $tmp],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $repoRoot
            );
            $this->assertIsResource($proc);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $this->assertSame("2\n", $stdout);
        } finally {
            @unlink($tmp);
        }
    }
}
