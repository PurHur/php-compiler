<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * array_multisort() VM smoke (#1212).
 */
final class ArrayMultisortBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$a = [30, 10, 20];
$b = ['c', 'a', 'b'];
array_multisort($a, $b);
echo implode(',', $a), "\n";
echo implode(',', $b), "\n";
PHP;

    private const EXPECT = <<<'TXT'
10,20,30
a,b,c

TXT;

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php'));
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_array_multisort_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n" . self::CODE);
        $proc = proc_open(
            ['php', $repo . '/' . $bin, $tmp],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc));
        @unlink($tmp);

        return $stdout;
    }
}
