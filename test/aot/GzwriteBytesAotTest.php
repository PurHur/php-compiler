<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: gzwrite/gzputs byte counts (#30787).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class GzwriteBytesAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'gzwrite_bytes.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('gzwrite bytes AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
