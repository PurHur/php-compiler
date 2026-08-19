<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: strcoll/strnatcmp named Zend stub params (#23694).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class StrcollStrnatcmpNamed23694AotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'strcoll_strnatcmp_named_23694.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('strcoll/strnatcmp named AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
