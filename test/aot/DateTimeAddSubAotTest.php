<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: DateTime::add/sub + DateTimeImmutable::add/sub (#30760).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class DateTimeAddSubAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'datetime_add_sub.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('datetime add/sub AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
