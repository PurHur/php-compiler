<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: date_get_last_errors + DateTime(Immutable)::getLastErrors (#30749).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class DateGetLastErrorsAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'date_get_last_errors.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('date_get_last_errors AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
