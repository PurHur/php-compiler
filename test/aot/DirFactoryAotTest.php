<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: dir() Directory factory (#30757).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class DirFactoryAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'dir_factory.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('dir factory AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
