<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Enum case as function parameter / property default must compile and materialize singleton (#5885, #9715, #9747).
 */
final class VmEnumDefaultParameterTest extends TestCase
{
    /**
     * @group static_enum_property_default
     */
    public function testBackedEnumStaticPropertyDefaultIsCaseSingleton(): void
    {
        $code = <<<'PHP'
<?php
enum G: string { case X = 'x'; }
class C { public static G $g = G::X; }
echo C::$g->name, "\n";
var_export(C::$g);
echo (C::$g === G::X) ? " same\n" : " diff\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_static_default_property.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertStringContainsString("X\n", $output);
        $this->assertStringContainsString('\\G::X', $output);
        $this->assertStringContainsString('same', $output);
    }

    public function testBackedEnumDefaultPropertyIsCaseSingleton(): void
    {
        $code = <<<'PHP'
<?php
enum E: int {
    case A = 1;
    case B = 2;
}
class P {
    public E $e = E::A;
}
$p = new P();
var_export($p->e);
echo ($p->e === E::A) ? "same\n" : "diff\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_default_property.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertStringContainsString('\\E::A', $output);
        $this->assertStringContainsString('same', $output);
    }

    public function testBackedEnumDefaultParameterIsCaseSingleton(): void
    {
        $code = <<<'PHP'
<?php
enum E: int {
    case A = 1;
    case B = 2;
}
function f(E $e = E::A) {
    return $e;
}
var_export([f(), f(E::B)]);
echo (f() === E::A) ? "same\n" : "diff\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_default_parameter.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertStringContainsString('\\E::A', $output);
        $this->assertStringContainsString('same', $output);
    }
}
