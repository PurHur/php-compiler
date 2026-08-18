<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #32252 — new self/parent/static in a free function is a Zend compile-time fatal */
final class PseudoClassNewCompileFatalTest extends TestCase
{
    /**
     * @dataProvider illegalFreeFunctionNewProvider
     */
    public function testFreeFunctionPseudoClassNewFailsAtCompileTime(string $keyword): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use "'.$keyword.'" when no class scope is active');
        $runtime->parseAndCompile(
            '<?php function f() { return new '.$keyword.'; } echo "accepted\n";',
            $keyword.'_new_no_scope.php'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function illegalFreeFunctionNewProvider(): iterable
    {
        yield 'static' => ['static'];
        yield 'self' => ['self'];
        yield 'parent' => ['parent'];
    }

    public function testThrowNewStaticInFreeFunctionFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use "static" when no class scope is active');
        $runtime->parseAndCompile(
            '<?php function f() { throw new static; } echo "accepted\n";',
            'throw_new_static_no_scope.php'
        );
    }

    public function testMethodNewStaticStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php class C { public function m() { return new static; } } echo "ok";',
            'method_new_static_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('ok', ob_get_clean());
    }

    public function testFileLevelDeadNewStaticStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php if (false) { new static; } echo "accepted";',
            'file_level_new_static.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('accepted', ob_get_clean());
    }

    public function testUnboundClosureNewStaticStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php $f = function () { return new static; }; echo "accepted";',
            'closure_new_static.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('accepted', ob_get_clean());
    }

    public function testStaticCallNoScopeStillFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use "static" when no class scope is active');
        $runtime->parseAndCompile(
            '<?php function f() { static::foo(); } echo "accepted\n";',
            'static_call_no_scope_still.php'
        );
    }
}
