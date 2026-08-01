<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Call + falsy/null/nested-empty array literal arg binding (#26367, Zend/zend_execute.c).
 */
final class CallFalsyArrayLiteralArgTest extends TestCase
{
    public function testVmMatchesZendForFalsyArrayLiteralSiblingCall(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/repro/issue_26367_call_falsy_array_literal.php';
        $zend = [];
        $vm = [];
        $zendExit = 0;
        $vmExit = 0;
        exec('php '.escapeshellarg($path).' 2>/dev/null', $zend, $zendExit);
        exec(
            'php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($path).' 2>/dev/null',
            $vm,
            $vmExit
        );
        self::assertSame(0, $zendExit, 'zend exit');
        self::assertSame(0, $vmExit, 'vm exit');
        self::assertSame(implode("\n", $zend), implode("\n", $vm));
        self::assertStringContainsString('unserialize=__PHP_Incomplete_Class', implode("\n", $vm));
    }
}
