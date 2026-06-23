<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Property default `new` expressions (issues #3391, #5362, #10693). */
final class PropertyDefaultNewTest extends TestCase
{
    public function testInstanceTypedPropertyDefaultNewCompileErrors(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('New expressions are not supported in this context');
        $runtime->parseAndCompile(<<<'PHP'
<?php
class Box {
    public stdClass $inner = new stdClass();
}
PHP, 'property_default_new_instance_typed.php');
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

    public function testInstanceUntypedPropertyDefaultNewIsPerInstance(): void
    {
        $this->assertOutput(<<<'PHP'
<?php
class Box {
    public $inner = new stdClass();
}
$a = new Box();
$b = new Box();
echo ($a->inner instanceof stdClass) ? "1\n" : "0\n";
echo ($a->inner !== $b->inner) ? "1\n" : "0\n";
PHP, "1\n1\n");
    }

    public function testPromotedTypedPropertyDefaultNewStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class Box {
    public function __construct(public array $items = []) {}
}
class C {
    public function __construct(public Box $y = new Box([])) {}
}
PHP, 'property_default_new_promoted.php');
        $this->assertNotNull($block);
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
