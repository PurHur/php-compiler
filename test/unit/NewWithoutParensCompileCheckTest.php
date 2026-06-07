<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Bare `new Class` in class constant initializers (#6549); property defaults allowed (#5362). */
final class NewWithoutParensCompileCheckTest extends TestCase
{
    public function testClassConstNewWithoutParensCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    const X = new stdClass;
}
PHP);
    }

    public function testPropertyDefaultNewWithoutParensCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public static $s = new stdClass;
    public $p = new stdClass;
}
PHP, 'new_without_parens_property.php');
        $this->assertNotNull($block);
    }

    public function testPropertyDefaultNewWithParensStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public $p = new stdClass();
}
PHP, 'new_with_parens.php');
        $this->assertNotNull($block);
    }

    private function expectCompileError(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(NewWithoutParensCompileCheck::MESSAGE);
        $runtime->parseAndCompile($code, 'new_without_parens.php');
    }
}
