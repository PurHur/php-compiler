<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @group aot
 */
final class Issue34662BackedEnumInitAotTest extends TestCase
{
    public function testBackedEnumCaseFetchMatchesZendUnderAot(): void
    {
        $src = dirname(__DIR__).'/repro/issue_34662_backed_enum_init.php';
        $this->assertFileExists($src);

        $zend = $this->runPhp($src);
        $this->assertSame("1 B x\n", $zend, 'Zend reference');

        $bin = sys_get_temp_dir().'/issue_34662_'.getmypid().'.bin';
        $compile = $this->runCmd([
            PHP_BINARY,
            dirname(__DIR__, 2).'/bin/compile.php',
            '-o',
            $bin,
            $src,
        ]);
        $this->assertSame(0, $compile['rc'], "AOT compile failed:\n".$compile['out']);
        $this->assertFileExists($bin);

        $aot = $this->runCmd([$bin]);
        $this->assertSame(0, $aot['rc'], "AOT run failed:\n".$aot['out']);
        $this->assertSame($zend, $aot['out']);
    }

    /** @return array{rc:int,out:string} */
    private function runPhp(string $file): string
    {
        $r = $this->runCmd([PHP_BINARY, $file]);
        $this->assertSame(0, $r['rc'], $r['out']);

        return $r['out'];
    }

    /**
     * @param list<string> $cmd
     * @return array{rc:int,out:string}
     */
    private function runCmd(array $cmd): array
    {
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, dirname(__DIR__, 2), [
            'PHP_COMPILER_HELPER_RUNTIME_O' => '0',
            'PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR' => sys_get_temp_dir().'/phpc-34662-'.getmypid(),
        ]);
        $this->assertIsResource($proc);
        $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);

        return ['rc' => $rc, 'out' => $out];
    }
}
