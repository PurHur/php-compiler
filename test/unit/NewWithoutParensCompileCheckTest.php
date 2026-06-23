<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** `new` in property initializers and class constants rejected (#10391, #10693, Zend/zend_compile.c). */
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

    public function testInstanceTypedPropertyDefaultNewCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public DateTime $d = new DateTime('2020-01-01');
}
PHP);
    }

    public function testInstancePropertyDefaultNewWithoutParensCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public $p = new stdClass;
}
PHP);
    }

    public function testInstancePropertyDefaultNewWithParensCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public $p = new stdClass();
}
PHP);
    }

    public function testPromotedPropertyDefaultNewWithParensStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public function __construct(public stdClass $p = new stdClass()) {}
}
PHP, 'promoted_new_with_parens.php');
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
