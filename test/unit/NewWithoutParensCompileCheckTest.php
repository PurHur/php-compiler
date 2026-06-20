<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** `new` in static property initializers rejected; class constants allowed PHP 8.3+ (#10198). */
final class NewWithoutParensCompileCheckTest extends TestCase
{
    public function testClassConstNewWithoutParensCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    const X = new stdClass;
}
PHP, 'new_without_parens_class_const.php');
        $this->assertNotNull($block);
    }

    public function testStaticPropertyDefaultNewWithoutParensCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public static $s = new stdClass;
}
PHP);
    }

    public function testStaticTypedPropertyDefaultNewCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public static DateTime $d = new DateTime('2020-01-01');
}
PHP);
    }

    public function testInstancePropertyDefaultNewWithoutParensCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
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

    public function testClassConstNewWithParensCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public function __construct(public int $n = 0) {}
}
class Holder {
    public const X = new C(1);
}
PHP, 'class_const_new_with_parens.php');
        $this->assertNotNull($block);
    }

    public function testClassConstNewEmptyArgsWithParensCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public const X = new stdClass();
}
PHP, 'class_const_new_empty_args.php');
        $this->assertNotNull($block);
    }

    public function testClassConstArrayWithNewCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public function __construct(public int $n = 0) {}
}
class Holder {
    public const X = [new C()];
}
PHP, 'class_const_array_with_new.php');
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
