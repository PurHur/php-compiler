<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * RecursiveArrayIterator bad $array TypeError cites ArrayIterator::__construct (#31626).
 *
 * php-src: ext/spl/spl_array.c — zim_ArrayIterator___construct shared with RAI
 */
final class Issue31626RecursiveArrayIteratorTypeErrorCiteTest extends TestCase
{
    public function testVmBadArrayTypeErrorCitesParentConstruct(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_recursivearrayiterator_typeerror_cites_parent.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_recursivearrayiterator_typeerror_cites_parent.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "TypeError:ArrayIterator::__construct(): Argument #1 (\$array) must be of type array, null given\n"
            ."TypeError:ArrayIterator::__construct(): Argument #1 (\$array) must be of type array, bool given\n"
            ."TypeError:ArrayIterator::__construct(): Argument #1 (\$array) must be of type array, int given\n",
            $out
        );
    }
}
