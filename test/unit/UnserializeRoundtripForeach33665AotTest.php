<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT foreach after unserialize(serialize(ArrayObject|ArrayIterator)) (#33665).
 *
 * Leftover of #33654: compile-time O: tagging does not cover runtime wires.
 */
final class UnserializeRoundtripForeach33665AotTest extends TestCase
{
    public function testVmIteratorForeachDispatchesUntypedValueBox(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/VM/VmIteratorForeach.php'
        );
        $this->assertStringContainsString('hashtableFromUntypedValueBox', $src);
        $this->assertStringContainsString('emitIsRuntimeHtBackedClassId', $src);
        $this->assertStringContainsString('#33665', $src);
    }

    /**
     * @dataProvider reproProvider
     */
    public function testAotMatchesZend(string $reproRel): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/'.$reproRel;
        $this->assertFileExists($repro);

        $zend = $this->runPhp($repro);
        $bin = sys_get_temp_dir().'/phpc-33665-'.md5($reproRel).'.bin';
        $compile = $this->runCmd([
            PHP_BINARY,
            $root.'/bin/compile.php',
            '-o',
            $bin,
            $repro,
        ]);
        $this->assertSame(0, $compile['rc'], $compile['out'].$compile['err']);
        $aot = $this->runCmd([$bin]);
        $this->assertSame(0, $aot['rc'], $aot['out'].$aot['err']);
        $this->assertSame($zend['out'], $aot['out']);
    }

    /** @return list<array{string}> */
    public static function reproProvider(): array
    {
        return [
            ['test/repro/arrayobject_unserialize_roundtrip_foreach_aot_33665.php'],
            ['test/repro/arrayiterator_unserialize_roundtrip_foreach_aot_33665.php'],
            // Literal O: path from #33654 must stay green.
            ['test/repro/arrayiterator_unserialize_foreach_aot_33654.php'],
        ];
    }

    /** @return array{rc:int,out:string,err:string} */
    private function runPhp(string $file): array
    {
        return $this->runCmd([PHP_BINARY, $file]);
    }

    /**
     * @param list<string> $cmd
     * @return array{rc:int,out:string,err:string}
     */
    private function runCmd(array $cmd): array
    {
        $des = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $des, $pipes, null, null);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);

        return ['rc' => $rc, 'out' => (string) $out, 'err' => (string) $err];
    }
}
