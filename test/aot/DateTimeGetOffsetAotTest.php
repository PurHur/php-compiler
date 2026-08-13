<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: DateTime(Immutable)::getOffset() (#30761).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class DateTimeGetOffsetAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'datetime_getoffset.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('datetime getOffset AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
