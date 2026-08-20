<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: DateTime::format / getTimestamp execute after construct (#32691).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class DateTimeFormatExecuteAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'datetime_format_execute.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('datetime format execute AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
