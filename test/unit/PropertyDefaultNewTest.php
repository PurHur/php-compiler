<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Property default `new` — all instance properties compile-reject per Zend (#10693). */
final class PropertyDefaultNewTest extends TestCase
{
    public function testInstanceTypedPropertyDefaultNewCompileErrors(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(NewWithoutParensCompileCheck::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
class Box {
    public stdClass $inner = new stdClass();
}
PHP, 'property_default_new_instance_typed.php');
    }

    public function testInstanceUntypedPropertyDefaultNewCompileErrors(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(NewWithoutParensCompileCheck::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
class Box {
    public $inner = new stdClass();
}
PHP, 'property_default_new_instance_untyped.php');
    }

    public function testStaticPropertyDefaultNewCompileErrors(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(NewWithoutParensCompileCheck::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public static $x = new stdClass();
}
PHP, 'property_default_new_static.php');
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
}
