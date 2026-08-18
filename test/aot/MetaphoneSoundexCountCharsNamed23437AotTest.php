<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: metaphone/count_chars named Zend stub params (#23437).
 * soundex() AOT execute hangs positionally too (pre-existing; not in this fixture).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class MetaphoneSoundexCountCharsNamed23437AotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'metaphone_soundex_count_chars_named_23437.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('metaphone/count_chars named AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
