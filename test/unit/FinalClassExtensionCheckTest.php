<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3406 */
final class FinalClassExtensionCheckTest extends TestCase
{
    public function testExtendFinalClassFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
final class F {}
class C extends F {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class C cannot extend final class F');
        $runtime->parseAndCompile($code, 'extend_final.php');
    }

    public function testNonFinalExtensionCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {}
class Child extends Base {}
echo Child::class, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("Child\n", ob_get_clean());
    }

    public function testFinalClassWithoutChildCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
final class F {}
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'final_only.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testIndirectExtensionThroughNonFinalParentFails(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
final class F {}
class Mid extends F {}
class Leaf extends Mid {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class Mid cannot extend final class F');
        $runtime->parseAndCompile($code, 'chain.php');
    }
}
