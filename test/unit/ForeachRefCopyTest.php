<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** By-value foreach after by-ref must not leave referenced array slots (#5419). */
final class ForeachRefCopyTest extends TestCase
{
    public function testByValueForeachCopiesReferencedSlots(): void
    {
        $repoRoot = \dirname(__DIR__, 2);
        $vm = $repoRoot . '/bin/vm.php';
        $code = <<<'PHP'
<?php
$arr = [1, 2, 3];
foreach ($arr as &$v) {
    $v *= 2;
}
foreach ($arr as $v) {
}
var_export($arr);
echo "\n";
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'foreach_ref_copy_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $code);
        try {
            $cmd = [PHP_BINARY, $vm, $tmp];
            $proc = proc_open(
                $cmd,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $repoRoot
            );
            $this->assertIsResource($proc);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $this->assertSame(
                "array (\n  0 => 2,\n  1 => 4,\n  2 => 4,\n)\n",
                $stdout
            );
        } finally {
            @unlink($tmp);
        }
    }
}
