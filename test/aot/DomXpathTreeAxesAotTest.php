<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: DOMXPath parent/ancestor/sibling axes (#31773).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class DomXpathTreeAxesAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'dom_xpath_tree_axes.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('DOMXPath tree axes AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
