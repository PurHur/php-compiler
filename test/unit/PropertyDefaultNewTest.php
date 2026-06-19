<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Property default `new` expressions (issues #3391, #5362). */
final class PropertyDefaultNewTest extends TestCase
{
    public function testInstancePropertyDefaultNewIsPerInstance(): void
    {
        $this->assertOutput(<<<'PHP'
<?php
class Box {
    public stdClass $inner = new stdClass();
}
$a = new Box();
$b = new Box();
echo ($a->inner instanceof stdClass) ? "1\n" : "0\n";
echo ($a->inner !== $b->inner) ? "1\n" : "0\n";
PHP, "1\n1\n");
    }

    public function testStaticPropertyDefaultNewCompileErrors(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('New expressions are not supported in this context');
        $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public static $x = new stdClass();
}
PHP, 'property_default_new_static.php');
    }

    public function testPropertyDefaultNewWithConstructorArgs(): void
    {
        $this->assertOutput(<<<'PHP'
<?php
class Box {
    public function __construct(public array $items = []) {}
}
class C {
    public $y = new Box([]);
}
$c = new C();
echo ($c->y instanceof Box && $c->y->items === []) ? "1\n" : "0\n";
PHP, "1\n");
    }

    private function assertOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'property_default_new.php'));
        $out = ob_get_clean();
        $this->assertSame($expected, $out);
    }
}
