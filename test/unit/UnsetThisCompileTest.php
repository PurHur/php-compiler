<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5436 */
final class UnsetThisCompileTest extends TestCase
{
    public function testUnsetThisRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function m(): void {
        unset($this);
    }
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot unset $this');
        $runtime->parseAndCompile($code, 'unset_this.php');
    }

    public function testUnsetThisPropertyStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public $p = 1;
    public function clear(): void {
        unset($this->p);
    }
}
PHP;
        $block = $runtime->parseAndCompile($code, 'unset_this_prop.php');
        $this->assertNotNull($block);
    }
}
