<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: date_isodate_set + DateTime(Immutable)::setISODate (#30748).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class DateIsodateSetAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'date_isodate_set.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('date isodate set AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
