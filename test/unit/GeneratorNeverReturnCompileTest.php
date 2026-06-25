<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\GeneratorNeverReturnCompileCheck;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7351 — yield with :never return type; #11666 — yield with :void return type */
final class GeneratorNeverReturnCompileTest extends TestCase
{
    /**
     * @dataProvider voidGeneratorProvider
     */
    public function testVoidGeneratorFailsAtCompileTime(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(GeneratorNeverReturnCompileCheck::VOID_MESSAGE);
        $runtime->parseAndCompile($code, 'void_gen.php');
    }

    /** @return iterable<string, array{string}> */
    public static function voidGeneratorProvider(): iterable
    {
        yield 'top-level yield' => [<<<'PHP'
<?php
function gen(): void {
    yield 1;
}
PHP];
        yield 'yield from' => [<<<'PHP'
<?php
function gen(): void {
    yield from [1];
}
PHP];
        yield 'instance method' => [<<<'PHP'
<?php
class C {
    public function gen(): void {
        yield 1;
    }
}
PHP];
    }

    public function testVoidWithoutYieldStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
function noop(): void {
}
noop();
PHP, 'void_no_yield.php');
        $this->assertNotNull($block);
    }

    /**
     * @dataProvider neverGeneratorProvider
     */
    public function testNeverGeneratorFailsAtCompileTime(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(GeneratorNeverReturnCompileCheck::MESSAGE);
        $runtime->parseAndCompile($code, 'never_gen.php');
    }

    /** @return iterable<string, array{string}> */
    public static function neverGeneratorProvider(): iterable
    {
        yield 'top-level yield' => [<<<'PHP'
<?php
function gen(): never {
    yield 1;
}
PHP];
        yield 'yield from' => [<<<'PHP'
<?php
function gen(): never {
    yield from [1];
}
PHP];
        yield 'instance method' => [<<<'PHP'
<?php
class C {
    public function gen(): never {
        yield 1;
    }
}
PHP];
    }

    public function testNeverWithoutYieldStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
function stop(): never {
    exit('gone');
}
stop();
PHP, 'never_no_yield.php');
        $this->assertNotNull($block);
    }

    public function testGeneratorWithoutNeverStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
function gen(): Generator {
    yield 1;
}
foreach (gen() as $v) {
    echo $v;
}
PHP, 'generator_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('1', ob_get_clean());
    }
}
