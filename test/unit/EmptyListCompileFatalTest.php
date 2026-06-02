<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #4525 — empty list assignment compile-time fatal */
final class EmptyListCompileFatalTest extends TestCase
{
    /**
     * @dataProvider emptyListProvider
     */
    public function testEmptyListAssignmentFailsAtCompileTime(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use empty list');
        $runtime->parseAndCompile($code, 'empty_list.php');
    }

    /** @return iterable<string, array{string}> */
    public static function emptyListProvider(): iterable
    {
        yield 'list()' => ['<?php list() = [];'];
        yield 'short []' => ['<?php [] = [];'];
        yield 'list skip only' => ['<?php list(,) = [1];'];
    }

    public function testNonEmptyListStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile('<?php [$a] = [1]; echo $a;', 'list_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('1', ob_get_clean());
    }
}
