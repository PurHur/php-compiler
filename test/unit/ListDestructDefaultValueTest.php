<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #14325 */
final class ListDestructDefaultValueTest extends TestCase
{
    public function testArrayDestructDefaultSlotRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Assignments can only happen to writable values');
        $runtime->parseAndCompile('<?php [$a = 1] = [2];', 'list_default.php');
    }

    public function testListDestructDefaultSlotRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Assignments can only happen to writable values');
        $runtime->parseAndCompile('<?php list($a = 1) = [2];', 'list_default.php');
    }

    public function testForeachListDestructDefaultSlotRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Assignments can only happen to writable values');
        $runtime->parseAndCompile(
            '<?php foreach ([[1, 2]] as [$a = 0, $b]) { echo $a . $b; }',
            'foreach_list_default.php'
        );
    }

    public function testListDestructWithoutDefaultsStillCompiles(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile('<?php [$a, $b] = [1, 2]; echo $a + $b;', 'list_ok.php'));
        $this->assertSame('3', ob_get_clean());
    }
}
