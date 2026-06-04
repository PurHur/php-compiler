<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Enum case as function parameter default must compile and materialize singleton (#5885).
 */
final class VmEnumDefaultParameterTest extends TestCase
{
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
