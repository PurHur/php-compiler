<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * range() INF/-INF bounds → ValueError (#27927, ext/standard/array.c).
 */
final class RangeNonfiniteBoundsTest extends TestCase
{
    public function testVmNonfiniteBoundsMatchZend(): void
    {
        $code = <<<'PHP'
foreach ([[0, INF], [INF, 0], [0, -INF], [1.5, INF]] as $args) {
    try {
        $r = range($args[0], $args[1]);
        echo 'ok:', count($r), "\n";
    } catch (ValueError $e) {
        echo 'ValueError:', $e->getMessage(), "\n";
    }
}
echo 'finite:', implode(',', range(1, 3)), "\n";
PHP;
        $expect = "ValueError:Invalid range supplied: start=0 end=inf\n"
            ."ValueError:Invalid range supplied: start=inf end=0\n"
            ."ValueError:Invalid range supplied: start=0 end=inf\n"
            ."ValueError:Invalid range supplied: start=2 end=inf\n"
            ."finite:1,2,3\n";
        $this->assertSame($expect, $this->runBin('bin/vm.php', $code));
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_range_nf_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            ['php', $repo.'/'.$bin, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        @unlink($tmp);
        $this->assertSame(0, proc_close($proc));

        return (string) $out;
    }
}
