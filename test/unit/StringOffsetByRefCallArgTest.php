<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * By-ref call args on string offsets must Error like Zend SEND_REF (#29523 / #21910).
 */
final class StringOffsetByRefCallArgTest extends TestCase
{
    public function testVmRejectsByRefStringOffsetCallArg(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
error_reporting(E_ALL);
function f(&$x){ $x = "Z"; }
$s = "ab";
try {
    f($s[0]);
    echo "str_ok s=$s\n";
} catch (\Throwable $e) {
    echo get_class($e), ":", $e->getMessage(), "\n";
}
echo $s, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'string_offset_byref_call_arg.php'));
        self::assertSame(
            "Error:Cannot create references to/from string offsets\nab\n",
            ob_get_clean()
        );
    }

    public function testVmAllowsByRefArrayDimCallArg(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(&$x){ $x = 5; }
$a = [1, 2];
f($a[0]);
echo "arr=", $a[0], "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'array_dim_byref_call_arg.php'));
        self::assertSame("arr=5\n", ob_get_clean());
    }
}
