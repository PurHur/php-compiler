<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #3518 / #4891 — backed enum ->value; echo on case throws Error (Zend zend_enum.c). */
final class VmEnumStringContextTest extends TestCase
{
    public function testBackedEnumValueAndIdentity(): void
    {
        $code = <<<'PHP'
<?php
enum Status: string {
    case Active = 'active';
    case Pending = 'pending';
}
echo Status::Active->value, "\n";
var_dump(Status::Active === Status::Active);
echo Status::Pending->value, "\n";
echo "done\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_string_context.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "active\nbool(true)\npending\ndone\n",
            $output
        );
    }

    public function testBackedEnumEchoThrowsError(): void
    {
        $code = <<<'PHP'
<?php
enum Status: string { case Active = 'active'; }
try {
    echo Status::Active;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_echo_backed.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "Object of class Status could not be converted to string\n",
            $output
        );
    }

    public function testIntBackedEnumEchoThrowsError(): void
    {
        $code = <<<'PHP'
<?php
enum N: int { case X = 1; }
try {
    echo N::X;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_int_backed_echo.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "Object of class N could not be converted to string\n",
            $output
        );
    }

    public function testBackedEnumPrintThrowsError(): void
    {
        $code = <<<'PHP'
<?php
enum S: string { case A = 'a'; }
try {
    print S::A;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_print_backed.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "Object of class S could not be converted to string\n",
            $output
        );
    }

    public function testHeredocEnumInterpolationThrowsError(): void
    {
        $code = <<<'PHP'
<?php
enum E: string { case X = 'x'; }
$e = E::X;
try {
    echo <<<HD
e=$e
HD;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_heredoc_backed.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "Object of class E could not be converted to string\n",
            $output
        );
    }
}
