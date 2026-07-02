<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #14879 */
final class ListDestructMixKeyedTest extends TestCase
{
    public function testListMixKeyedUnkeyedRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot mix keyed and unkeyed array entries in assignments');
        $runtime->parseAndCompile('<?php list(0 => $x, $y) = [1, 2];', 'list_mix.php');
    }

    public function testArrayDestructMixKeyedUnkeyedRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot mix keyed and unkeyed array entries in assignments');
        $runtime->parseAndCompile('<?php [0 => $x, $y] = [1, 2];', 'array_mix.php');
    }

    public function testForeachListMixKeyedUnkeyedRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot mix keyed and unkeyed array entries in assignments');
        $runtime->parseAndCompile(
            '<?php foreach ([[1, 2]] as list(0 => $x, $y)) { echo $x; }',
            'foreach_list_mix.php'
        );
    }

    public function testForeachArrayDestructMixKeyedUnkeyedRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot mix keyed and unkeyed array entries in assignments');
        $runtime->parseAndCompile(
            '<?php foreach ([[1, 2]] as [0 => $x, $y]) { echo $x; }',
            'foreach_array_mix.php'
        );
    }

    public function testPureKeyedListStillCompiles(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile('<?php ["a" => $x] = ["a" => 1]; echo $x;', 'keyed_ok.php'));
        $this->assertSame('1', ob_get_clean());
    }

    public function testPureUnkeyedListStillCompiles(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile('<?php list($x, $y) = [1, 2]; echo $x + $y;', 'unkeyed_ok.php'));
        $this->assertSame('3', ob_get_clean());
    }
}
