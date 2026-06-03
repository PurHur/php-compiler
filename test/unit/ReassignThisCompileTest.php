<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #4865 */
final class ReassignThisCompileTest extends TestCase
{
    public function testReassignThisRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function m(): void {
        $this = new C();
    }
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot re-assign $this');
        $runtime->parseAndCompile($code, 'reassign_this.php');
    }

    public function testThisPropertyAssignStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public int $x = 0;
    public function m(): void {
        $this->x = 1;
    }
}
PHP;
        $block = $runtime->parseAndCompile($code, 'this_prop.php');
        $this->assertNotNull($block);
    }
}
