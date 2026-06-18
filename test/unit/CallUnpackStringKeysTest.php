<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ScriptExit;
use PHPUnit\Framework\TestCase;

/** Call-time ...$arr spread: named unpack from string keys (#4669), unknown keys (#4321). */
final class CallUnpackStringKeysTest extends TestCase
{
    public function testVmRejectsUnknownNamedKeys(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function sum(int $a, int $b, int $c): int {
    return $a + $b + $c;
}
$args = ['x' => 1, 'y' => 2, 'z' => 3];
sum(...$args);
PHP;
        try {
            $runtime->run($runtime->parseAndCompile($code, 'call_unpack_string_keys.php'));
            self::fail('expected Error');
        } catch (ScriptExit $e) {
            // Zend SAPI fatal — VM maps uncaught Error to ScriptExit.
            self::assertSame(255, $e->status);
        }
    }

    public function testVmRejectsUnknownNamedKeysInTryCatch(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function sum(int $a, int $b, int $c): int {
    return $a + $b + $c;
}
$args = ['x' => 1, 'y' => 2, 'z' => 3];
try {
    sum(...$args);
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'call_unpack_string_keys_try.php'));
        self::assertSame("Unknown named parameter \$x\n", ob_get_clean());
    }

    public function testVmAcceptsPackedList(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function sum(int $a, int $b, int $c): int {
    return $a + $b + $c;
}
$args = [1, 2, 3];
echo sum(...$args);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'call_unpack_packed.php'));
        self::assertSame('6', ob_get_clean());
    }

    public function testVmNamedUnpackMatchesParameterNames(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function pair(int $a, int $b): void {
    echo "$a,$b\n";
}
function ordered(int $a, int $b = 0): void {
    echo "$a,$b\n";
}
function optional(string $a = 'd'): void {
    echo "$a\n";
}
pair(...['a' => 1, 'b' => 2]);
ordered(...['b' => 5, 'a' => 1]);
optional(...['a' => 'named']);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'call_unpack_named_keys.php'));
        self::assertSame("1,2\n1,5\nnamed\n", ob_get_clean());
    }

    /** Issue #9391 / #9199: string-key unpack binds named params; defaults apply for unfilled slots. */
    public function testVmStringKeyNamedUnpackWithDefaultParam(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f($a, $b = 2) {
    echo $a, ',', $b, "\n";
}
$args = ['a' => 1];
f(...$args);
f(...['a' => 1]);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'call_unpack_string_keys_default.php'));
        self::assertSame("1,2\n1,2\n", ob_get_clean());
    }

    /** Integer-key unpack must stay positional (php-src SEND_UNPACK). */
    public function testVmIntegerKeyUnpackRemainsPositional(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f($a, $b = 2, $c = 3) {
    echo $a, ',', $b, ',', $c, "\n";
}
f(...[0 => 10, 1 => 20]);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'call_unpack_int_keys.php'));
        self::assertSame("10,20,3\n", ob_get_clean());
    }
}
