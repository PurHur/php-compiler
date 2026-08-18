<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #32227 — self/parent/static::method in a free function is a Zend compile-time fatal */
final class PseudoClassStaticCallCompileFatalTest extends TestCase
{
    /**
     * @dataProvider illegalFreeFunctionCallProvider
     */
    public function testFreeFunctionPseudoClassCallFailsAtCompileTime(string $keyword): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use "'.$keyword.'" when no class scope is active');
        $runtime->parseAndCompile(
            '<?php function f() { '.$keyword.'::foo(); } echo "accepted\n";',
            $keyword.'_call_no_scope.php'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function illegalFreeFunctionCallProvider(): iterable
    {
        yield 'static' => ['static'];
        yield 'self' => ['self'];
        yield 'parent' => ['parent'];
    }

    public function testStaticClassInFreeFunctionUsesZendWording(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use "static" when no class scope is active');
        $runtime->parseAndCompile(
            '<?php function f() { echo static::class; } echo "accepted\n";',
            'static_class_no_scope.php'
        );
    }

    public function testMethodStaticCallStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php class C { public static function foo() { echo "ok"; } public static function m() { static::foo(); } } C::m();',
            'method_static_call_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('ok', ob_get_clean());
    }

    public function testFileLevelDeadStaticCallStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php if (false) { static::foo(); } echo "accepted";',
            'file_level_static_call.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('accepted', ob_get_clean());
    }

    public function testUnboundClosureStaticCallStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php class C { public static function m(): void {} } $f = function (): void { static::m(); }; echo "accepted";',
            'closure_static_call.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('accepted', ob_get_clean());
    }
}
