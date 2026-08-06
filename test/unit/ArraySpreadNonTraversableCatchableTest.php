<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Array-literal [...$nonTraversable] must throw catchable Error (#27952). */
final class ArraySpreadNonTraversableCatchableTest extends TestCase
{
    public function testVmCatchesIntegerSpread(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
try {
    $a = 123;
    $b = [...$a];
    echo "ok\n";
} catch (Throwable $e) {
    echo 'caught:', get_class($e), ':', $e->getMessage(), "\n";
}
echo "after\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'array_spread_non_traversable_catchable.php'));
        self::assertSame(
            "caught:Error:Only arrays and Traversables can be unpacked\nafter\n",
            ob_get_clean()
        );
    }

    public function testVmObjectSpreadIsTypeError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
try {
    $a = new stdClass();
    $b = [...$a];
    echo "ok\n";
} catch (Throwable $e) {
    echo 'caught:', get_class($e), ':', $e->getMessage(), "\n";
}
echo "after\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'array_spread_object_non_traversable.php'));
        self::assertSame(
            "caught:TypeError:Only arrays and Traversables can be unpacked\nafter\n",
            ob_get_clean()
        );
    }

    public function testCallTimeUnpackStillTypeError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function id($x) {
    return $x;
}
try {
    id(...'ab');
    echo "ok\n";
} catch (Throwable $e) {
    echo 'caught:', get_class($e), ':', $e->getMessage(), "\n";
}
echo "after\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'call_unpack_control.php'));
        self::assertSame(
            "caught:TypeError:Only arrays and Traversables can be unpacked\nafter\n",
            ob_get_clean()
        );
    }
}
