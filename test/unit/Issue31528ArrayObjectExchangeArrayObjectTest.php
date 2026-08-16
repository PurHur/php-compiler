<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ArrayObject::exchangeArray(object|array) — share ArrayObject storage (#31528).
 *
 * php-src: ext/spl/spl_array.c — zim_ArrayObject_exchangeArray / spl_array_set_array.
 */
final class Issue31528ArrayObjectExchangeArrayObjectTest extends TestCase
{
    public function testVmExchangeArrayObjectMatchesZend(): void
    {
        $code = <<<'PHP'
<?php
error_reporting(E_ALL);
$a = new ArrayObject([0]);
$b = new ArrayObject([1, 2]);
$a->exchangeArray($b);
echo json_encode(iterator_to_array($a)), "\n";
$b[0] = 99;
echo json_encode(iterator_to_array($a)), "\n";
try {
    (new ArrayObject())->exchangeArray('x');
    echo "BAD\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31528_arrayobject_exchangearray_object.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "[1,2]\n[99,2]\nTypeError: ArrayObject::exchangeArray(): Argument #1 (\$array) must be of type array, string given\n",
            $out
        );
    }
}
