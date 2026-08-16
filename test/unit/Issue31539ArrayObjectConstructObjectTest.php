<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ArrayObject::__construct(object|array) — share ArrayObject storage (#31539).
 *
 * php-src: ext/spl/spl_array.c — zim_ArrayObject___construct / spl_array_set_array.
 */
final class Issue31539ArrayObjectConstructObjectTest extends TestCase
{
    public function testVmConstructObjectSharesStorageMatchesZend(): void
    {
        $code = <<<'PHP'
<?php
error_reporting(E_ALL);
$b = new ArrayObject([1, 2]);
$a = new ArrayObject($b);
echo json_encode(iterator_to_array($a)), "\n";
$b[0] = 99;
echo json_encode(iterator_to_array($a)), "\n";
try {
    new ArrayObject('x');
    echo "BAD\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31539_arrayobject_construct_object.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "[1,2]\n[99,2]\nTypeError: ArrayObject::__construct(): Argument #1 (\$array) must be of type array, string given\n",
            $out
        );
    }
}
