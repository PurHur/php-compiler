<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #5293: ?? must not use truthiness — only null/undefined. */
final class NullCoalesceDefinedVmTest extends TestCase
{
    public function testDefinedOperandsAreNotReplaced(): void
    {
        $this->assertVmOutput(
            '<?php
var_export([] ?? 1);
echo "\n";
echo is_object((object) [] ?? 1) ? "(object) array(\n)\n" : "1\n";
var_export(false ?? 1);
echo "\n";
var_export(0 ?? 1);
echo "\n";
',
            "array (\n)\n(object) array(\n)\nfalse\n0\n"
        );
    }

    private function assertVmOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit $e) {
            // exit() in compiled code
        }
        $actual = ob_get_clean();
        $this->assertSame($expected, $actual, 'VM stdout');
    }
}
