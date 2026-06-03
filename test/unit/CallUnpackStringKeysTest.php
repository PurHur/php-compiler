<?php

declare(strict_types=1);

namespace PHPCompiler;

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
        } catch (\Error $e) {
            self::assertSame('Unknown named parameter $x', $e->getMessage());
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
}
