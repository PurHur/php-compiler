<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: date_date_set/date_time_set + DateTime(Immutable)::setDate/setTime (#30747).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class DateDateTimeSetAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'date_date_time_set.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('date date/time set AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
