<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: DateInterval::format execute after construct (#32699).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class DateIntervalFormatExecuteAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'dateinterval_format_execute.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('dateinterval format execute AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
