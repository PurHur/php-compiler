<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** `new` in class constant initializers (#6549, #9484, #9804); property defaults allowed (#5362). */
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

    public function testClassConstNewWithParensCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public function __construct(public int $n = 0) {}
}
class Holder {
    public const X = new C(1);
}
PHP);
    }

    public function testClassConstNewEmptyArgsWithParensCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public const X = new stdClass();
}
PHP);
    }

    public function testClassConstArrayWithNewCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public function __construct(public int $n = 0) {}
}
class Holder {
    public const X = [new C()];
}
PHP);
    }

    private function expectCompileError(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(NewWithoutParensCompileCheck::MESSAGE);
        $runtime->parseAndCompile($code, 'new_without_parens.php');
    }
}
