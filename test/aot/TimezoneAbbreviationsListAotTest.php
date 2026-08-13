<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: timezone_abbreviations_list + DateTimeZone::listAbbreviations (#30780).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class TimezoneAbbreviationsListAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'timezone_abbreviations_list.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('timezone_abbreviations_list AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
