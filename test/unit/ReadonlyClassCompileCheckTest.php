<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3551 */
final class ReadonlyClassCompileCheckTest extends TestCase
{
    public function testNonReadonlyChildOfReadonlyParentFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class A {}
class B extends A {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Non-readonly class B cannot extend readonly class A');
        $runtime->parseAndCompile($code, 'non_readonly_child.php');
    }

    public function testReadonlyChildOfNonReadonlyParentFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {}
readonly class R extends C {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Readonly class R cannot extend non-readonly class C');
        $runtime->parseAndCompile($code, 'readonly_child.php');
    }

    public function testReadonlyClassPropertyDefaultFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class R2 {
    public int $x = 1;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Readonly property R2::$x cannot have default value');
        $runtime->parseAndCompile($code, 'readonly_default.php');
    }

    public function testReadonlyExtendsReadonlyCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class A {}
readonly class B extends A {}
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'readonly_chain.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }
}
