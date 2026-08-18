<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: base_convert/addcslashes/hash_file named Zend stub params (#23507).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class BaseConvertAddcslashesHashFileNamed23507AotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'named_args_23507_base_convert_addcslashes_hash_file.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('named args 23507 AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
