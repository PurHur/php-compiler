<?php

declare(strict_types=1);

namespace PHPCompiler;

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
}
