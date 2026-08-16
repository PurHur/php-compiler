<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * RegexIterator::setMode invalid mode → Zend-shaped ValueError citation (#31573).
 *
 * php-src: ext/spl/spl_iterators.c — zim_RegexIterator_setMode
 */
final class Issue31573RegexIteratorSetModeValueErrorTest extends TestCase
{
    public function testVmSetModeInvalidModeCitesSetModeArg1(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_regexiterator_setmode_valueerror.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_regexiterator_setmode_valueerror.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            'ValueError:RegexIterator::setMode(): Argument #1 ($mode) must be RegexIterator::MATCH, '
            .'RegexIterator::GET_MATCH, RegexIterator::ALL_MATCHES, RegexIterator::SPLIT, '
            .'or RegexIterator::REPLACE'."\n",
            $out
        );
    }
}
