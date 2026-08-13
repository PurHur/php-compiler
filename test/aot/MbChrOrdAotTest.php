<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: mb_chr()/mb_ord() UTF-8 euro fold (#30759).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class MbChrOrdAotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'mb_chr_ord.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('mb_chr/mb_ord AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
