<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: DOMXPath or/and predicates (#32050).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class DomXpathOrAndAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'dom_xpath_or_and.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('DOMXPath or/and AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
