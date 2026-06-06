<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #6868 */
final class InterfaceConstVisibilityCheckTest extends TestCase
{
    public function testProtectedInterfaceConstFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    protected const X = 1;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Access type for interface constant I::X must be public');
        $runtime->parseAndCompile($code, 'interface_protected_const.php');
    }

    public function testPrivateInterfaceConstFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    private const X = 1;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Access type for interface constant I::X must be public');
        $runtime->parseAndCompile($code, 'interface_private_const.php');
    }

    public function testPublicInterfaceConstCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public const X = 1;
}
class C implements I {}
echo I::X, "\n";
echo C::X, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'interface_public_const.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("1\n1\n", ob_get_clean());
    }
}
