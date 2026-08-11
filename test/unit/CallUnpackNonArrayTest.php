<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Call-time ...$expr spread must throw TypeError for non-array operands (#4322, #30023). */
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

    public function testVmProfile84AppendsGivenType(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
function f(...$a) {}
$out = [];
foreach ([1, 1.5, 'ab', true, false, null, new stdClass] as $v) {
    try {
        f(...$v);
    } catch (Throwable $e) {
        $out[] = get_class($e).':'.$e->getMessage();
    }
}
echo implode("\n", $out), "\n";
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'call_unpack_non_array_given_84.php'));
            self::assertSame(
                "TypeError:Only arrays and Traversables can be unpacked, int given\n"
                ."TypeError:Only arrays and Traversables can be unpacked, float given\n"
                ."TypeError:Only arrays and Traversables can be unpacked, string given\n"
                ."TypeError:Only arrays and Traversables can be unpacked, true given\n"
                ."TypeError:Only arrays and Traversables can be unpacked, false given\n"
                ."TypeError:Only arrays and Traversables can be unpacked, null given\n"
                ."TypeError:Only arrays and Traversables can be unpacked, stdClass given\n",
                ob_get_clean()
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }
}
