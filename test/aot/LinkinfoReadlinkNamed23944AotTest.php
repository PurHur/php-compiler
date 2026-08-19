<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: readlink/linkinfo named Zend stub path (#23944).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class LinkinfoReadlinkNamed23944AotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'linkinfo_readlink_named_23944.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('linkinfo/readlink named AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
