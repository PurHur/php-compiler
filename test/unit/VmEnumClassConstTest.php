<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * Enum user `const` vs `case` — E::FOO must resolve (zend_enum.c, #5054).
 */
final class VmEnumClassConstTest extends TestCase
{
    public function testBackedEnumClassConstAndCaseValue(): void
    {
        $code = <<<'PHP'
<?php
enum E: int {
    case A = 1;
    public const FOO = 2;
}
var_export([E::FOO, E::A->value]);
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_class_const.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame("array (\n  0 => 2,\n  1 => 1,\n)\n", $output);
        $entry = $runtime->vmContext->classes['e'];
        $this->assertTrue($entry->isEnum);
        $this->assertArrayHasKey('foo', $entry->constants);
        $this->assertSame(2, $entry->constants['foo']->toInt());
        $this->assertArrayHasKey('a', $entry->constants);
        $this->assertArrayHasKey('a', $entry->enumCaseCanonicalNames);
        $this->assertArrayNotHasKey('foo', $entry->enumCaseCanonicalNames);
    }

    public function testBackedEnumTypedClassConst(): void
    {
        if (!CompilerVersion::supportsTypedClassConstants()) {
            $this->markTestSkipped('typed class constants require CompilerVersion 8.4.0+');
        }
        $code = <<<'PHP'
<?php
enum E: int {
    case A = 1;
    public const int FOO = 2;
}
echo E::FOO, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_typed_class_const.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame("2\n", $output);
        $entry = $runtime->vmContext->classes['e'];
        $this->assertArrayHasKey('foo', $entry->constants);
        $this->assertSame(2, $entry->constants['foo']->toInt());
    }

    public function testUnitEnumClassConst(): void
    {
        $code = <<<'PHP'
<?php
enum U {
    case X;
    public const BAR = 'ok';
}
echo U::BAR;
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'unit_enum_class_const.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('ok', $output);
    }

    public function testTypedClassConstWithEnumType(): void
    {
        if (!CompilerVersion::supportsTypedClassConstants()) {
            $this->markTestSkipped('typed class constants require CompilerVersion 8.4.0+');
        }
        $code = <<<'PHP'
<?php
enum Color: string { case Red = 'r'; case Blue = 'b'; }
class Palette {
    public const Color PRIMARY = Color::Red;
}
var_export(Palette::PRIMARY);
echo "\n";
echo Palette::PRIMARY->name, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'typed_enum_class_const.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame("\\Color::Red\nRed\n", $output);
        $entry = $runtime->vmContext->classes['palette'];
        $this->assertArrayHasKey('primary', $entry->constants);
        $stored = $entry->constants['primary']->resolveIndirect();
        $this->assertTrue(
            Variable::TYPE_ENUM_CASE === $stored->type
            || (Variable::TYPE_OBJECT === $stored->type && $stored->toObject()->isEnumCase)
        );
    }

    public function testClassConstEnumCaseForwardReference(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public const ITEM = E::A;
}
enum E: int { case A = 1; }
var_export(C::ITEM);
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'class_const_enum_forward_ref.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame("\\E::A\n", $output);
        $entry = $runtime->vmContext->classes['c'];
        $this->assertArrayHasKey('item', $entry->constants);
        $stored = $entry->constants['item']->resolveIndirect();
        $this->assertTrue(
            Variable::TYPE_ENUM_CASE === $stored->type
            || (Variable::TYPE_OBJECT === $stored->type && $stored->toObject()->isEnumCase)
        );
    }
}
