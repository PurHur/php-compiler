<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * RegexIterator getPregFlags/getMode/getFlags excess argc → ACE like Zend (#31594).
 *
 * php-src: ext/spl/spl_iterators.c — ZEND_PARSE_PARAMETERS_NONE
 */
final class Issue31594RegexIteratorGettersExcessArgcTest extends TestCase
{
    public function testVmExcessArgcMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_regexiterator_getters_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_regexiterator_getters_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "getPregFlags ArgumentCountError:RegexIterator::getPregFlags() expects exactly 0 arguments, 1 given\n"
            ."getMode ArgumentCountError:RegexIterator::getMode() expects exactly 0 arguments, 1 given\n"
            ."getFlags ArgumentCountError:RegexIterator::getFlags() expects exactly 0 arguments, 1 given\n",
            $out
        );
    }
}
