<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: DateTime(Immutable) createFromInterface/Immutable/Mutable (#30762).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class DateTimeCreateFromInterfaceAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'datetime_create_from_interface.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('datetime createFrom AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
