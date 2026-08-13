<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: date_timezone_get/set + DateTime(Immutable)::getTimezone (#30746).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class DateTimezoneGetSetAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'date_timezone_get_set.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('date timezone AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
