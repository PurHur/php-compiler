<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #4785 — backed enum in double-quoted strings must Error (Zend zend_enum.c). */
final class VmEnumStringInterpolationTest extends TestCase
{
    public function testBackedEnumDoubleQuotedInterpolationThrows(): void
    {
        $code = <<<'PHP'
<?php
enum Color: string { case Red = 'r'; }
$c = Color::Red;
try {
    echo "$c";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_string_interpolation.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "Error: Object of class Color could not be converted to string\n",
            $output
        );
    }
}
