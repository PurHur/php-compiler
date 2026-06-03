<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #4970 */
final class NeverUnionTypeTest extends TestCase
{
    public function testNeverInUnionReturnTypeRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): string|never {
    throw new Exception('x');
}
echo "compiled\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'never_union.php');
    }

    public function testNeverInIntersectionReturnTypeRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function g(): int&never {
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'never_intersection.php');
    }

    public function testStandaloneNeverReturnTypeStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): never {
    throw new Exception('x');
}
f();
PHP;
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile($code, 'never_standalone.php'));
            $this->fail('Expected uncaught exception');
        } catch (\Throwable $e) {
            $this->assertSame('x', $e->getMessage());
        }
        ob_end_clean();
    }
}
