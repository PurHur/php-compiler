<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #14879 */
final class ListDestructMixKeyedUnkeyedTest extends TestCase
{
    private const MESSAGE = 'Cannot mix keyed and unkeyed array entries in assignments';

    public function testListMixKeyedUnkeyedRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(self::MESSAGE);
        $runtime->parseAndCompile('<?php list(0 => $x, $y) = [1, 2];', 'list_mix.php');
    }

    public function testArrayDestructMixKeyedUnkeyedRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(self::MESSAGE);
        $runtime->parseAndCompile('<?php [0 => $x, $y] = [1, 2];', 'array_mix.php');
    }

    public function testForeachListMixKeyedUnkeyedRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(self::MESSAGE);
        $runtime->parseAndCompile(
            '<?php foreach ([[1, 2]] as list(0 => $x, $y)) { echo $x . $y; }',
            'foreach_list_mix.php'
        );
    }

    public function testUnkeyedListDestructStillCompiles(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile('<?php list($x, $y) = [1, 2]; echo $x . $y;', 'list_ok.php'));
        $this->assertSame('12', ob_get_clean());
    }

    public function testKeyedListDestructStillCompiles(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile(
            '<?php ["a" => $x, "b" => $y] = ["a" => 1, "b" => 2]; echo $x . $y;',
            'keyed_ok.php'
        ));
        $this->assertSame('12', ob_get_clean());
    }

    public function testKeyedSpreadListDestructStillCompiles(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile(
            '<?php ["label" => $label, ...$rest] = ["label" => "L", "a" => 1]; echo $label, count($rest);',
            'keyed_spread_ok.php'
        ));
        $this->assertSame('L1', ob_get_clean());
    }
}
