<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Empty MultipleIterator::current()/key() → RuntimeException (#31625).
 *
 * php-src: ext/spl/spl_iterators.c — zim_MultipleIterator_current / key
 */
final class Issue31625MultipleIteratorEmptyCurrentKeyTest extends TestCase
{
    public function testVmMatchesZendEmptyCurrentKey(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_multipleiterator_current_empty.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_multipleiterator_current_empty.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "flags=NEED_ANY valid=false\n"
            ."current RuntimeException:Called current() on an invalid iterator\n"
            ."key RuntimeException:Called key() on an invalid iterator\n"
            ."flags=NEED_ALL valid=false\n"
            ."current RuntimeException:Called current() on an invalid iterator\n"
            ."key RuntimeException:Called key() on an invalid iterator\n",
            $out
        );
    }
}
