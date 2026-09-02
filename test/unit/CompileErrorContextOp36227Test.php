<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Compile-time fatals from CFG lowering attach file/line via context op (#36227). */
final class CompileErrorContextOp36227Test extends TestCase
{
    public function testNullsafeWriteContextIncludesSourceLocation(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class B { public int $x = 1; }
class A { public ?B $b = null; }
$a = new A();
$a?->b->x = 2;
PHP;

        try {
            $runtime->parseAndCompile($code, 'nullsafe_write.php');
            $this->fail('Expected CompileFatal for nullsafe write context');
        } catch (CompileFatal $e) {
            $this->assertSame('nullsafe_write.php', $e->sourceFile);
            $this->assertSame(5, $e->sourceLine);
            $this->assertStringContainsString("Can't use nullsafe operator in write context", $e->getMessage());
        }
    }
}
