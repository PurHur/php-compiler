<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Call-time ...$expr spread must throw TypeError for non-array operands (#4322). */
final class CallUnpackNonArrayTest extends TestCase
{
    public function testVmRejectsIntegerSpread(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function id($x) {
    return $x;
}
id(...42);
PHP;
        try {
            $runtime->run($runtime->parseAndCompile($code, 'call_unpack_non_array.php'));
            self::fail('expected TypeError');
        } catch (\TypeError $e) {
            self::assertSame('Only arrays and Traversables can be unpacked', $e->getMessage());
        }
    }

    public function testVmRejectsIntegerSpreadInTryCatch(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function id($x) {
    return $x;
}
try {
    id(...42);
    echo "no error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'call_unpack_non_array_try.php'));
        self::assertSame("Only arrays and Traversables can be unpacked\n", ob_get_clean());
    }
}
