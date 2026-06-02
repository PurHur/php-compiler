<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Call-time ...$arr spread must reject non-list arrays (#4321). */
final class CallUnpackStringKeysTest extends TestCase
{
    public function testVmRejectsStringKeyedArray(): void
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
            self::fail('expected TypeError');
        } catch (\TypeError $e) {
            self::assertSame('Cannot unpack array with string keys', $e->getMessage());
        }
    }

    public function testVmRejectsStringKeyedArrayInTryCatch(): void
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
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'call_unpack_string_keys_try.php'));
        self::assertSame("Cannot unpack array with string keys\n", ob_get_clean());
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
}
