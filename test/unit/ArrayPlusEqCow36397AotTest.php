<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPUnit\Framework\TestCase;

/**
 * AOT `$a += […]` array union + COW (#36397).
 *
 * php-src: Zend/zend_operators.c add_function; Zend/zend_vm_def.h ZEND_ASSIGN_OP.
 */
final class ArrayPlusEqCow36397AotTest extends TestCase
{
    public function testExclusivePlusEqMatchesZend(): void
    {
        $this->assertAotMatchesFile(
            'array_plus_eq_exclusive_36397.php',
            "AHAS|1\n"
        );
    }

    public function testCowPlusEqMatchesZend(): void
    {
        $this->assertAotMatchesFile(
            'array_plus_eq_cow_36397.php',
            "BOK|AHAS|1|1\n"
        );
    }

    public function testNestedPlusEqMatchesZend(): void
    {
        $this->assertAotMatchesFile(
            'array_plus_eq_nested_36397.php',
            "BOK|AHAS\n"
        );
    }

    public function testGuardAllowsValueBoxPlusHashtable(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2).'/lib/VM/VmArrayNumericOperandGuard.php');
        $this->assertNotFalse($src);
        $this->assertStringContainsString('valueBoxHashtable', $src);
        $this->assertStringContainsString('isValueBoxOperand', $src);
        $this->assertStringContainsString('#36397', $src);
        $assign = file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Concern/AssignOperand.php');
        $this->assertNotFalse($assign);
        $this->assertStringContainsString('AssignOp-fused', $assign);
    }

    private function assertAotMatchesFile(string $repro, string $expect): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/'.$repro;
        $this->assertFileExists($src);
        $bin = sys_get_temp_dir().'/phpc_plus_eq_36397_'.getmypid().'_'.md5($repro).'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($compile.' 2>&1', $out, $ec);
        $this->assertSame(0, $ec, "compile failed:\n".implode("\n", $out));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runEc);
        @unlink($bin);
        $this->assertSame(0, $runEc, "run failed:\n".implode("\n", $runOut));
        $this->assertSame($expect, implode("\n", $runOut)."\n");
    }
}
