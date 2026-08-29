<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** @covers issue #24508 */
final class PhpcreditsNamedFlags24508AotCompileTest extends TestCase
{
    public function testPhpcreditsNamedFlagsAotLint(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/phpcredits_named_flags_24508.php';
        $cmd = [PHP_BINARY, $bin, '-l', $target];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for phpcredits named flags (#24508)'
        );
    }

    public function testPhpcreditsNamedFlagsAotMatchesVm(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/fixtures/aot/compile-only/phpcredits_named_flags_24508.php';
        $bin = sys_get_temp_dir().'/phpc_24508_'.getmypid().'.bin';

        $compile = proc_open(
            [PHP_BINARY, $root.'/bin/compile.php', '-o', $bin, $src],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root
        );
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($compile), trim($compileErr !== false ? $compileErr : ''));

        $vm = proc_open(
            [PHP_BINARY, $root.'/bin/vm.php', $src],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root
        );
        $this->assertIsResource($vm);
        fclose($pipes[0]);
        $vmOut = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($vm);

        $aot = proc_open(
            [$bin],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root
        );
        $this->assertIsResource($aot);
        fclose($pipes[0]);
        $aotOut = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($aot);
        @unlink($bin);

        $this->assertSame(
            $vmOut,
            $aotOut,
            "AOT output must match VM for phpcredits named flags (#24508)\nVM:\n{$vmOut}\nAOT:\n{$aotOut}"
        );
    }
}
