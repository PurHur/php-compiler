<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * RecursiveTreeIterator(ArrayIterator) → TypeError citing RecursiveCachingIterator (#31596).
 *
 * php-src: ext/spl/spl_iterators.c — spl_recursive_it_it_construct RIT_RecursiveTreeIterator
 */
final class Issue31596RecursiveTreeIteratorNonRecursiveTypeErrorTest extends TestCase
{
    public function testVmMatchesZendTypeErrorCitation(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_recursivetreeiterator_nonrecursive.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_recursivetreeiterator_nonrecursive.php');
        ob_start();
        $rt->run($block);
        $out = (string) ob_get_clean();
        $this->assertSame(
            "TypeError:RecursiveCachingIterator::__construct(): Argument #1 (\$iterator) must be of type RecursiveIterator, ArrayIterator given\n",
            $out
        );
        $this->assertStringNotContainsString('InvalidArgumentException', $out);
    }

    public function testIteratorAggregateProducingRecursiveIteratorAccepted(): void
    {
        $code = <<<'PHP'
<?php
class Agg implements IteratorAggregate {
    public function getIterator(): Traversable {
        return new RecursiveArrayIterator([1]);
    }
}
$it = new RecursiveTreeIterator(new Agg());
echo $it instanceof RecursiveTreeIterator ? "ok\n" : "bad\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'rti_agg.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("ok\n", (string) ob_get_clean());
    }
}
