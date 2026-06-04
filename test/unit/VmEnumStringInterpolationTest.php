<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #4819 / #5508 — enum case string contexts must Error (Zend zend_enum.c). */
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

    public function testExplicitStringCastOnEnumCaseThrows(): void
    {
        $code = <<<'PHP'
<?php
enum Color: string { case Red = 'r'; }
$c = Color::Red;
try {
    $s = (string) $c;
    echo $s;
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_string_cast.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "Error: Object of class Color could not be converted to string\n",
            $output
        );
    }

    public function testStrvalOnEnumCaseThrows(): void
    {
        $code = <<<'PHP'
<?php
enum Color: string { case Red = 'r'; }
try {
    echo strval(Color::Red);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_strval.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "Error: Object of class Color could not be converted to string\n",
            $output
        );
    }

    public function testConcatWithEnumCaseThrows(): void
    {
        $code = <<<'PHP'
<?php
enum Color: string { case Red = 'r'; }
try {
    echo 'x' . Color::Red;
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_concat.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "Error: Object of class Color could not be converted to string\n",
            $output
        );
    }
}
