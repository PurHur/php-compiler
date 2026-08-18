<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: is_uploaded_file named Zend stub param filename (#28853).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class IsUploadedFileNamed28853AotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'is_uploaded_file_named_28853.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('is_uploaded_file named AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
