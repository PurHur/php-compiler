<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * CachingIterator multi TOSTRING flags → Zend-shaped ValueError (#31551).
 *
 * php-src: ext/spl/spl_iterators.c — spl_cit_check_flags
 */
final class Issue31551CachingIteratorMultiToStringFlagsTest extends TestCase
{
    public function testVmMatchesZendExclusiveToStringFlags(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_cachingiterator_multi_tostring_flags.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_cachingiterator_multi_tostring_flags.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $msg = 'must contain only one of CachingIterator::CALL_TOSTRING, '
            .'CachingIterator::TOSTRING_USE_KEY, CachingIterator::TOSTRING_USE_CURRENT, '
            .'or CachingIterator::TOSTRING_USE_INNER';
        $this->assertSame(
            "ValueError: CachingIterator::__construct(): Argument #2 (\$flags) {$msg}\n"
            ."ValueError: CachingIterator::setFlags(): Argument #1 (\$flags) {$msg}\n",
            $out
        );
    }
}
