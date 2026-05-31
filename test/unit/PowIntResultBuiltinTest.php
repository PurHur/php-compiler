<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * pow() int vs float return typing (issue #3678).
 */
final class PowIntResultBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
echo gettype(pow(2, 3)), ' ', pow(2, 3), "\n";
echo gettype(pow(2.0, 3)), ' ', pow(2.0, 3), "\n";
PHP;

    private const EXPECT = <<<'TXT'
integer 8
double 8
TXT;

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php', self::CODE));
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_pow_int_vm_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $proc = proc_open(
            ['php', $repo.'/'.$bin, $tmp],
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
        @unlink($tmp);
        $this->assertSame(0, $exit, trim((string) $err));

        return preg_replace('/\r\n?/', "\n", trim((string) $out)) ?? '';
    }
}
