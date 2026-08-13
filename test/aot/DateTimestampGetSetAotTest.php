<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: date_timestamp_get/set + DateTime(Immutable) timestamp accessors (#30745).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class DateTimestampGetSetAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'date_timestamp_get_set.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('date timestamp AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
